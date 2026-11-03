<?php

/**
 * Two graduates are duplicates when their NAME matches after normalization
 * (case, spacing and punctuation ignored) - wherever they are saved: the
 * course, school, graduation year, Latin honor and date are NOT part of the
 * key. The same person is often on two schools' lists (another campus, or a
 * list uploaded under the wrong school), the same course is written many ways
 * ("BSIT" vs "Bachelor of Science in Information Technology"), and OCR drops
 * dates and mangles honors, so any of those would only hide real duplicates.
 *
 * Among copies, the one that already has a filed application PREVAILS (see
 * group_duplicates()): it is the one kept, and it is never removed.
 */
function normalize_duplicate_text(string $value): string
{
    $value = str_replace(['.', ','], ' ', $value);
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

    return mb_strtoupper($value);
}

function duplicate_group_key(array $row): string
{
    return normalize_duplicate_text((string) $row['graduate_name']);
}

/**
 * The strict "exact copy" key: school, year, honor, name and course all match.
 * Used only by the bulk update of applications to collapse copies of one record
 * (see bulk_hge_collapse_duplicates()), where merging two different schools'
 * records would silently file an application against the wrong one.
 */
function duplicate_exact_copy_key(array $row): string
{
    return implode('|', [
        (int) $row['school_id'],
        (int) $row['graduation_year'],
        $row['latin_honor'],
        normalize_duplicate_text((string) $row['graduate_name']),
        normalize_duplicate_text((string) $row['course']),
    ]);
}

/**
 * @param array<int, int>|null $schoolIds
 * @param array<int, int>|null $years
 */
function fetch_duplicate_candidates(PDO $pdo, ?array $schoolIds = null, ?array $years = null): array
{
    $conditions = [];
    $params = [];

    if ($schoolIds !== null) {
        if ($schoolIds === []) {
            return [];
        }
        $conditions[] = 'g.school_id IN (' . implode(',', array_fill(0, count($schoolIds), '?')) . ')';
        array_push($params, ...array_map('intval', $schoolIds));
    }
    if ($years !== null) {
        if ($years === []) {
            return [];
        }
        $conditions[] = 'g.graduation_year IN (' . implode(',', array_fill(0, count($years), '?')) . ')';
        array_push($params, ...array_map('intval', $years));
    }

    $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

    $stmt = $pdo->prepare(
        "SELECT g.id, g.upload_id, g.school_id, g.graduate_name, g.course, g.latin_honor, g.graduation_date,
                g.graduation_year, s.school_name, s.campus, u.original_filename, u.upload_number, u.stored_filename,
                a.application_date, (a.id IS NOT NULL) AS has_applied
         FROM latin_honor_graduates g
         JOIN schools s ON s.id = g.school_id
         JOIN latin_honor_uploads u ON u.id = g.upload_id
         LEFT JOIN graduate_applications a ON a.graduate_id = g.id
         {$whereSql}
         ORDER BY g.id ASC"
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Buckets candidate rows by duplicate key and keeps only buckets with 2+
 * members. Each group's members are ordered keeper-first: a copy that has a
 * filed application PREVAILS over the others (an application is the system's
 * confirmation of a record), otherwise the oldest (lowest id) wins.
 *
 * @return array<int, array{key: string, members: array}>
 */
function group_duplicates(array $rows): array
{
    $buckets = [];
    foreach ($rows as $row) {
        $buckets[duplicate_group_key($row)][] = $row;
    }

    $groups = [];
    foreach ($buckets as $key => $members) {
        if (count($members) < 2) {
            continue;
        }

        usort($members, static function (array $a, array $b): int {
            return ((int) $b['has_applied'] <=> (int) $a['has_applied']) ?: ((int) $a['id'] <=> (int) $b['id']);
        });

        $groups[] = ['key' => (string) $key, 'members' => $members];
    }

    usort($groups, static function (array $a, array $b): int {
        return strcmp($a['members'][0]['graduate_name'], $b['members'][0]['graduate_name']);
    });

    return $groups;
}

/**
 * Every duplicate group. Groups span schools and years, so the optional school
 * / year filters only choose which groups to SHOW: a group is kept when at
 * least one of its copies is at that school / in that year, and it is still
 * listed with all of its copies.
 *
 * @return array<int, array{key: string, members: array}>
 */
function find_duplicate_groups(PDO $pdo, ?int $schoolId = null, ?int $year = null): array
{
    $groups = group_duplicates(fetch_duplicate_candidates($pdo));

    if ($schoolId === null && $year === null) {
        return $groups;
    }

    return array_values(array_filter($groups, static function (array $group) use ($schoolId, $year): bool {
        foreach ($group['members'] as $member) {
            if (($schoolId === null || (int) $member['school_id'] === $schoolId)
                && ($year === null || (int) $member['graduation_year'] === $year)) {
                return true;
            }
        }

        return false;
    }));
}

/**
 * Number of duplicate groups that include at least one record from this
 * upload — the post-save "N possible duplicates" check. The copies may be at
 * any school, so every group is considered.
 */
function count_duplicates_for_upload(PDO $pdo, int $uploadId): int
{
    $count = 0;
    foreach (find_duplicate_groups($pdo) as $group) {
        foreach ($group['members'] as $member) {
            if ((int) $member['upload_id'] === $uploadId) {
                $count++;
                break;
            }
        }
    }

    return $count;
}

/**
 * Which of the given graduate ids (one Look-Up page of results) belong to a
 * duplicate group. One lean scan of every saved name (a copy can be
 * at any school), grouped by duplicate_group_key().
 *
 * @param array<int, int> $graduateIds
 * @return array<int, int>
 */
function flag_duplicates_for_rows(PDO $pdo, array $graduateIds): array
{
    $graduateIds = array_values(array_unique(array_map('intval', $graduateIds)));
    if ($graduateIds === []) {
        return [];
    }

    $keysById = [];
    $perKey = [];
    foreach ($pdo->query('SELECT id, graduate_name, course FROM latin_honor_graduates') as $row) {
        $key = duplicate_group_key($row);
        $keysById[(int) $row['id']] = $key;
        $perKey[$key] = ($perKey[$key] ?? 0) + 1;
    }

    return array_values(array_filter(
        $graduateIds,
        static fn (int $id): bool => isset($keysById[$id]) && $perKey[$keysById[$id]] > 1
    ));
}

/**
 * Moves the chosen duplicate copies to the recycle bin. Re-derives the
 * duplicate groups server-side (never trusts the browser's idea of what is
 * a duplicate) and refuses to remove every copy of a group, so at least one
 * always survives. Copies with a filed application are skipped (the FK
 * would block them anyway). Returns counts plus a readable skip list.
 *
 * @param array<int, int> $graduateIds
 * @return array{removed: int, skipped: array<int, string>}
 */
function remove_duplicate_graduates(PDO $pdo, array $graduateIds, ?int $userId, string $reason): array
{
    require_once __DIR__ . '/graduates.php';

    $graduateIds = array_values(array_unique(array_filter(array_map('intval', $graduateIds))));
    if ($graduateIds === []) {
        return ['removed' => 0, 'skipped' => []];
    }

    $groups = group_duplicates(fetch_duplicate_candidates($pdo));

    $removed = 0;
    $skipped = [];
    $handled = [];

    foreach ($groups as $group) {
        $memberIds = array_map(static fn ($m) => (int) $m['id'], $group['members']);
        $selected = array_values(array_intersect($memberIds, $graduateIds));
        if ($selected === []) {
            continue;
        }

        $handled = array_merge($handled, $selected);
        $name = $group['members'][0]['graduate_name'];

        if (count($selected) >= count($memberIds)) {
            $skipped[] = $name . ': AT LEAST ONE COPY MUST BE KEPT.';
            continue;
        }

        foreach ($selected as $id) {
            $result = delete_graduate($pdo, $id, $userId, $reason, 'duplicate_cleanup');
            if ($result['ok']) {
                $removed++;
            } else {
                $skipped[] = $name . ': ' . ($result['error'] ?? 'COULD NOT BE REMOVED.');
            }
        }
    }

    foreach (array_diff($graduateIds, $handled) as $id) {
        $skipped[] = 'RECORD #' . $id . ' IS NO LONGER PART OF A DUPLICATE GROUP.';
    }

    return ['removed' => $removed, 'skipped' => $skipped];
}

/**
 * The pre-save check on admin/review.php (SAVE RECORDS): groups of staged rows
 * that are duplicates by NAME (see duplicate_group_key()) of another
 * staged row of the same upload, or of a record already saved anywhere - any
 * school, any year - under a different upload. It is a prompt for a human to
 * decide, not an automatic dedup, so two different people who share a name are
 * shown too and can still be saved. A saved copy that already has a filed
 * application prevails (it comes first in 'saved', flagged has_applied).
 *
 * Callers must also load includes/functions.php.
 *
 * @param array<int, array> $rows  staged rows ($review['rows'])
 * @param array<string, array<int, array>> $savedByKey  find_saved_duplicates_by_name()
 * @return array<int, array{name: string, staged: array<int, array>, saved: array<int, array>}>
 */
function find_staged_duplicate_groups(array $rows, array $savedByKey): array
{
    $buckets = [];
    foreach ($rows as $index => $row) {
        if (normalize_name_for_matching((string) ($row['graduate_name'] ?? '')) === '') {
            continue;
        }
        $buckets[duplicate_group_key($row)][] = [
            'row_index' => (int) $index,
            'row_number' => (int) $index + 1,
            'name' => (string) $row['graduate_name'],
            'course' => (string) ($row['course'] ?? ''),
            'latin_honor' => (string) ($row['latin_honor'] ?? ''),
            'graduation_year' => (string) ($row['graduation_year'] ?? ''),
        ];
    }

    $groups = [];
    foreach ($buckets as $key => $staged) {
        $saved = $savedByKey[$key] ?? [];
        if (count($staged) < 2 && $saved === []) {
            continue;
        }
        $groups[] = ['name' => $staged[0]['name'], 'staged' => $staged, 'saved' => $saved];
    }

    return $groups;
}

/**
 * CONFIRMED DUPLICATE FINDINGS. When an administrator confirms, in the SAVE RECORDS duplicate prompt,
 * that a record of the review is a duplicate of a saved one, the review record is REMOVED and what was
 * found about it (school, course, year and date graduated, source file) is stored in
 * graduate_duplicate_findings against the saved ORIGINAL - the first record saved for that name
 * (duplicate_group_key()) under another upload. The original shows a "DUPLICATE FOUND (n)" badge in
 * Look-Up (tooltip on hover, details modal on click) and is the only record a search lists; the
 * duplicate itself is not saved. The table comes from database/add_graduate_duplicate_findings.sql on a
 * live DB. Callers must also load includes/functions.php.
 */

/**
 * The first saved record of every name, among the uploads other than $excludeUploadId.
 *
 * @return array<string, array<string, mixed>> keyed by duplicate_group_key()
 */
function find_original_records_by_name(PDO $pdo, int $excludeUploadId): array
{
    $stmt = $pdo->prepare(
        'SELECT g.id, g.graduate_name
         FROM latin_honor_graduates g
         WHERE g.upload_id <> :exclude_upload_id
         ORDER BY g.id ASC'
    );
    $stmt->execute(['exclude_upload_id' => $excludeUploadId]);

    $byKey = [];
    foreach ($stmt->fetchAll() as $row) {
        if (normalize_name_for_matching((string) $row['graduate_name']) === '') {
            continue;
        }
        $key = duplicate_group_key($row);
        if (!isset($byKey[$key])) {
            $byKey[$key] = $row;
        }
    }

    return $byKey;
}

/** True when the findings table exists (database/add_graduate_duplicate_findings.sql has been run). */
function duplicate_findings_table_exists(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM graduate_duplicate_findings LIMIT 1');

        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Stores one finding per confirmed duplicate row of the review that matches a saved original, then
 * reports which rows had none. Rows are the staged rows chosen by the administrator; the school and
 * source file are those of the upload under review. Everything is written in one transaction.
 *
 * @param array<int, array<string, mixed>> $stagedRows the chosen staged rows
 * @param array{upload_id: int, school: string, filename: string} $source the upload under review
 * @return array{tagged: int, untagged: int, originals: int} rows with an original, rows without one,
 *         and how many different originals were tagged
 * @throws \Throwable when the table is missing or a write fails (nothing is stored then)
 */
function record_duplicate_findings(PDO $pdo, array $source, array $stagedRows, ?int $confirmedBy): array
{
    $originals = find_original_records_by_name($pdo, $source['upload_id']);

    $insert = $pdo->prepare(
        'INSERT INTO graduate_duplicate_findings
            (original_graduate_id, upload_id, duplicate_name, school, course, latin_honor, graduation_year, graduation_date, source_filename, confirmed_by)
         VALUES (:original_id, :upload_id, :name, :school, :course, :honor, :year, :date, :filename, :confirmed_by)'
    );

    $tagged = 0;
    $untagged = 0;
    $taggedOriginals = [];

    $pdo->beginTransaction();
    try {
        foreach ($stagedRows as $row) {
            $original = normalize_name_for_matching((string) ($row['graduate_name'] ?? '')) === ''
                ? null
                : ($originals[duplicate_group_key($row)] ?? null);
            if ($original === null) {
                $untagged++;
                continue;
            }
            $insert->execute([
                'original_id' => (int) $original['id'],
                'upload_id' => $source['upload_id'],
                'name' => (string) $row['graduate_name'],
                'school' => $source['school'],
                'course' => (string) ($row['course'] ?? ''),
                'honor' => (string) ($row['latin_honor'] ?? ''),
                'year' => ($row['graduation_year'] ?? '') !== '' ? (int) $row['graduation_year'] : null,
                'date' => ($row['graduation_date'] ?? '') !== '' ? $row['graduation_date'] : null,
                'filename' => $source['filename'],
                'confirmed_by' => $confirmedBy,
            ]);
            $tagged++;
            $taggedOriginals[(int) $original['id']] = true;
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['tagged' => $tagged, 'untagged' => $untagged, 'originals' => count($taggedOriginals)];
}

/**
 * For a Look-Up page of results: per saved record that has findings, how many and the text of the
 * hover tooltip (the count and the latest finding). Nothing when the table does not exist yet.
 *
 * @param array<int, int> $graduateIds
 * @return array<int, array{count: int, tooltip: string}> keyed by graduate id
 */
function get_duplicate_findings_summary(PDO $pdo, array $graduateIds): array
{
    $graduateIds = array_values(array_unique(array_filter(array_map('intval', $graduateIds))));
    if ($graduateIds === []) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT original_graduate_id, school, course, graduation_year, graduation_date, source_filename, found_at
             FROM graduate_duplicate_findings
             WHERE original_graduate_id IN (' . implode(',', array_fill(0, count($graduateIds), '?')) . ')
             ORDER BY found_at DESC, id DESC'
        );
        $stmt->execute($graduateIds);
    } catch (\Throwable $e) {
        return [];
    }

    $summary = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) $row['original_graduate_id'];
        if (!isset($summary[$id])) {
            $summary[$id] = ['count' => 0, 'latest' => $row];
        }
        $summary[$id]['count']++;
    }

    $result = [];
    foreach ($summary as $id => $entry) {
        $latest = $entry['latest'];
        $date = $latest['graduation_date'] !== null ? date('d F Y', strtotime((string) $latest['graduation_date'])) : '';
        $parts = array_filter([
            $latest['school'],
            $latest['course'] !== '' ? $latest['course'] : null,
            $latest['graduation_year'] !== null ? 'YEAR GRADUATED ' . $latest['graduation_year'] : null,
            $date !== '' ? 'DATE GRADUATED ' . $date : null,
        ]);
        $result[$id] = [
            'count' => $entry['count'],
            'tooltip' => mb_strtoupper(
                'DUPLICATE FOUND ' . $entry['count'] . ' TIME' . ($entry['count'] === 1 ? '' : 'S') . '. LATEST: ' . implode(' — ', $parts)
                . ' (FROM ' . $latest['source_filename'] . '). CLICK FOR DETAILS.'
            ),
        ];
    }

    return $result;
}

/**
 * What the modal shows for a saved record that has findings: the original (read live) and every
 * duplicate found against it, newest first.
 *
 * @return array{name: string, original: array<string, mixed>, findings: array<int, array<string, mixed>>}|null
 */
function get_duplicate_finding_details(PDO $pdo, int $graduateId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT g.graduate_name, g.course, g.latin_honor, g.graduation_date, g.graduation_year,
                s.school_name, s.campus, u.original_filename, u.uploaded_at
         FROM latin_honor_graduates g
         JOIN latin_honor_uploads u ON u.id = g.upload_id
         LEFT JOIN schools s ON s.id = g.school_id
         WHERE g.id = :id'
    );
    $stmt->execute(['id' => $graduateId]);
    $original = $stmt->fetch();
    if ($original === false) {
        return null;
    }

    try {
        $findingsStmt = $pdo->prepare(
            'SELECT duplicate_name, school, course, latin_honor, graduation_year, graduation_date, source_filename, found_at
             FROM graduate_duplicate_findings
             WHERE original_graduate_id = :id
             ORDER BY found_at DESC, id DESC'
        );
        $findingsStmt->execute(['id' => $graduateId]);
        $findings = $findingsStmt->fetchAll();
    } catch (\Throwable $e) {
        return null;
    }
    if ($findings === []) {
        return null;
    }

    return [
        'name' => (string) $original['graduate_name'],
        'original' => [
            'school' => $original['school_name'] !== null ? format_school_display_name((string) $original['school_name'], $original['campus'] ?? null) : '',
            'course' => (string) $original['course'],
            'latin_honor' => (string) $original['latin_honor'],
            'graduation_date' => $original['graduation_date'],
            'graduation_year' => $original['graduation_year'],
            'filename' => (string) $original['original_filename'],
        ],
        'findings' => array_map(static fn (array $row): array => [
            'school' => (string) $row['school'],
            'course' => (string) $row['course'],
            'latin_honor' => (string) $row['latin_honor'],
            'graduation_date' => $row['graduation_date'],
            'graduation_year' => $row['graduation_year'],
            'filename' => (string) $row['source_filename'],
            'found_at' => (string) $row['found_at'],
        ], $findings),
    ];
}
