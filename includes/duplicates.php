<?php

/**
 * Two saved graduates are duplicates only when school, graduation year,
 * Latin honor, name and course all match after normalization — deliberately
 * conservative so two different people are never grouped. The date is NOT
 * part of the key (OCR frequently drops it).
 */
function normalize_duplicate_text(string $value): string
{
    $value = str_replace(['.', ','], ' ', $value);
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

    return mb_strtoupper($value);
}

function duplicate_group_key(array $row): string
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
 * filed application wins, otherwise the oldest (lowest id).
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
 * @return array<int, array{key: string, members: array}>
 */
function find_duplicate_groups(PDO $pdo, ?int $schoolId = null, ?int $year = null): array
{
    $rows = fetch_duplicate_candidates(
        $pdo,
        $schoolId !== null ? [$schoolId] : null,
        $year !== null ? [$year] : null
    );

    return group_duplicates($rows);
}

/**
 * Number of duplicate groups that include at least one record from this
 * upload — the post-save "N possible duplicates" check. Scoped to the
 * upload's school so it stays cheap.
 */
function count_duplicates_for_upload(PDO $pdo, int $uploadId): int
{
    $stmt = $pdo->prepare('SELECT school_id FROM latin_honor_uploads WHERE id = :id');
    $stmt->execute(['id' => $uploadId]);
    $schoolId = $stmt->fetchColumn();

    if ($schoolId === false) {
        return 0;
    }

    $count = 0;
    foreach (find_duplicate_groups($pdo, (int) $schoolId) as $group) {
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
 * duplicate group. One grouped scan over those rows' schools/years.
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

    $placeholders = implode(',', array_fill(0, count($graduateIds), '?'));
    $stmt = $pdo->prepare("SELECT DISTINCT school_id, graduation_year FROM latin_honor_graduates WHERE id IN ({$placeholders})");
    $stmt->execute($graduateIds);
    $pairs = $stmt->fetchAll();

    $schoolIds = array_values(array_unique(array_map(static fn ($p) => (int) $p['school_id'], $pairs)));
    $years = array_values(array_unique(array_map(static fn ($p) => (int) $p['graduation_year'], $pairs)));

    $flagged = [];
    foreach (group_duplicates(fetch_duplicate_candidates($pdo, $schoolIds, $years)) as $group) {
        foreach ($group['members'] as $member) {
            $flagged[(int) $member['id']] = true;
        }
    }

    return array_values(array_intersect($graduateIds, array_keys($flagged)));
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

    $placeholders = implode(',', array_fill(0, count($graduateIds), '?'));
    $stmt = $pdo->prepare("SELECT DISTINCT school_id FROM latin_honor_graduates WHERE id IN ({$placeholders})");
    $stmt->execute($graduateIds);
    $schoolIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $groups = group_duplicates(fetch_duplicate_candidates($pdo, $schoolIds));

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
 * that share a name, either with another staged row of the same upload or with
 * a record already saved for the same school and year under a different upload.
 * Name-only on purpose (see normalize_name_for_matching()) - it is a prompt for
 * a human to decide, not an automatic dedup, so two different people who share
 * a name are shown too and can still be saved.
 *
 * Callers must also load includes/functions.php.
 *
 * @param array<int, array> $rows  staged rows ($review['rows'])
 * @param array<string, array> $savedByName  find_possible_duplicate_graduates()
 * @return array<int, array{name: string, staged: array<int, array>, saved: ?array}>
 */
function find_staged_duplicate_groups(array $rows, array $savedByName): array
{
    $buckets = [];
    foreach ($rows as $index => $row) {
        $key = normalize_name_for_matching((string) ($row['graduate_name'] ?? ''));
        if ($key === '') {
            continue;
        }
        $buckets[$key][] = [
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
        $saved = $savedByName[$key] ?? null;
        if (count($staged) < 2 && $saved === null) {
            continue;
        }
        $groups[] = ['name' => $staged[0]['name'], 'staged' => $staged, 'saved' => $saved];
    }

    return $groups;
}
