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
                g.graduation_year, s.school_name, s.campus, u.original_filename, u.upload_number,
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
