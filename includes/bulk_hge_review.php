<?php

// Bulk-update applicants whose match was uncertain are kept in
// bulk_hge_review_items until an administrator acts on each one: file the
// application against one of the possible saved records, or dismiss it.
//
// Callers must also load includes/bulk_hge.php, includes/duplicates.php and
// includes/functions.php (see the note at the top of bulk_hge.php).

const BULK_HGE_REVIEW_REASONS = [
    'loose' => 'LOOSE MATCH',
    'ambiguous' => 'AMBIGUOUS',
    'claimed' => 'CONFLICT',
];

const BULK_HGE_REVIEW_REASON_HINTS = [
    'loose' => 'THE SAVED RECORD HAS NO MIDDLE NAME TO CONFIRM IT.',
    'ambiguous' => 'MORE THAN ONE SAVED RECORD FITS THIS PERSON.',
    'claimed' => 'THE SAME SAVED RECORD FITS MORE THAN ONE PERSON IN THE FILE.',
];

/**
 * Stable identity of an applicant across uploads, built the same way
 * parse_bulk_hge_rows() merges repeated people (so re-uploading the same file
 * never creates a second review item for the same person).
 */
function bulk_hge_person_key(array $person): string
{
    return sha1(
        bulk_hge_fold((string) $person['last']) . '|' . bulk_hge_fold((string) $person['first']) . '|'
        . (string) $person['mi'] . '|' . bulk_hge_fold((string) $person['middle']) . '|' . (string) $person['ext']
    );
}

/** "LAST, FIRST MIDDLE EXT" (the middle name, else the initial) for display. */
function bulk_hge_person_display(array $person): string
{
    $middle = (string) $person['middle'] !== '' ? (string) $person['middle'] : (string) $person['mi'];
    $ext = (string) $person['ext'];

    return trim($person['last'] . ', ' . $person['first'] . ($middle !== '' ? ' ' . $middle : '') . ($ext !== '' ? ' ' . $ext : ''));
}

/**
 * The friendly message when the review table has not been created yet on this
 * database (database/add_bulk_hge_review.sql), else null.
 */
function bulk_hge_review_missing_table_message(\Throwable $e): ?string
{
    if ($e instanceof \PDOException && ($e->errorInfo[0] ?? '') === '42S02') {
        return 'THE REVIEW LIST IS NOT SET UP ON THIS DATABASE YET. RUN database/add_bulk_hge_review.sql ONCE.';
    }

    return null;
}

/**
 * For the saved records that still exist: id => the date their application was
 * filed (null when none). A deleted record is simply absent.
 *
 * @param int[] $ids
 * @return array<int, ?string>
 */
function bulk_hge_review_applied_dates(PDO $pdo, array $ids): array
{
    $dates = [];
    foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 1000) as $chunk) {
        $in = implode(',', $chunk);
        $stmt = $pdo->query(
            "SELECT g.id, a.application_date
             FROM latin_honor_graduates g
             LEFT JOIN graduate_applications a ON a.graduate_id = g.id
             WHERE g.id IN ({$in})"
        );
        foreach ($stmt->fetchAll() as $row) {
            $dates[(int) $row['id']] = $row['application_date'] !== null ? (string) $row['application_date'] : null;
        }
    }

    return $dates;
}

/**
 * True when there is nothing left for an administrator to decide: every
 * possible saved record that still exists already has an application filed for
 * the very date the file gives (and at least one exists).
 *
 * @param int[] $candidateIds
 * @param array<int, ?string> $appliedDates  from bulk_hge_review_applied_dates()
 */
function bulk_hge_review_is_settled(array $candidateIds, array $appliedDates, string $date): bool
{
    $existing = 0;
    foreach ($candidateIds as $id) {
        if (!array_key_exists((int) $id, $appliedDates)) {
            continue;
        }
        $existing++;
        if ($appliedDates[(int) $id] !== $date) {
            return false;
        }
    }

    return $existing > 0;
}

/**
 * Closes the waiting items that are already settled (see
 * bulk_hge_review_is_settled()) - e.g. the same person typed twice in the file,
 * one spelling filed by an administrator. They are closed as dismissed with no
 * administrator, so the person is not raised again by a later upload.
 *
 * @return int  how many items were closed
 */
function bulk_hge_review_close_settled(PDO $pdo): int
{
    $rows = $pdo->query("SELECT id, application_date, candidate_ids FROM bulk_hge_review_items WHERE status = 'pending'")->fetchAll();

    $ids = [];
    foreach ($rows as $row) {
        foreach ((array) json_decode((string) $row['candidate_ids'], true) as $id) {
            $ids[] = (int) $id;
        }
    }
    if ($ids === []) {
        return 0;
    }

    $dates = bulk_hge_review_applied_dates($pdo, $ids);
    $settled = [];
    foreach ($rows as $row) {
        $candidateIds = (array) json_decode((string) $row['candidate_ids'], true);
        if (bulk_hge_review_is_settled($candidateIds, $dates, (string) $row['application_date'])) {
            $settled[] = (int) $row['id'];
        }
    }
    if ($settled === []) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "UPDATE bulk_hge_review_items SET status = 'dismissed', resolved_at = :resolved_at
         WHERE status = 'pending' AND id IN (" . implode(',', $settled) . ')'
    );
    $stmt->execute(['resolved_at' => date('Y-m-d H:i:s')]);

    return $stmt->rowCount();
}

/**
 * Saves the uncertain applicants of a confirmed preview. One item per person:
 * a person already waiting is refreshed (the earlier date is kept), and a
 * person an admin already filed or dismissed - or whose possible records are
 * all already filed for the file's date - is left alone.
 *
 * @param array<int, array> $review  'review' from bulk_hge_build_preview()
 * @return array{added: int, updated: int, skipped: int}
 */
function bulk_hge_review_save(PDO $pdo, array $review, int $userId, string $filename): array
{
    $allCandidates = [];
    foreach ($review as $item) {
        foreach ($item['candidate_ids'] as $id) {
            $allCandidates[] = (int) $id;
        }
    }
    $appliedDates = $allCandidates === [] ? [] : bulk_hge_review_applied_dates($pdo, $allCandidates);

    $select = $pdo->prepare('SELECT id, status FROM bulk_hge_review_items WHERE person_key = :person_key');
    $insert = $pdo->prepare(
        'INSERT INTO bulk_hge_review_items
            (person_key, last_name, first_name, mi, middle_name, ext_name, application_date, reason, candidate_ids, source_filename, file_row, created_by)
         VALUES
            (:person_key, :last_name, :first_name, :mi, :middle_name, :ext_name, :application_date, :reason, :candidate_ids, :source_filename, :file_row, :created_by)'
    );
    $update = $pdo->prepare(
        "UPDATE bulk_hge_review_items
         SET reason = :reason, candidate_ids = :candidate_ids, source_filename = :source_filename, file_row = :file_row,
             application_date = LEAST(application_date, :application_date)
         WHERE id = :id AND status = 'pending'"
    );

    $added = 0;
    $updated = 0;
    $skipped = 0;
    $filename = mb_substr($filename, 0, 100);

    foreach ($review as $item) {
        $person = $item['person'];
        if (bulk_hge_review_is_settled($item['candidate_ids'], $appliedDates, (string) $person['date'])) {
            $skipped++;
            continue;
        }
        $key = bulk_hge_person_key($person);
        $candidates = json_encode(array_values(array_map('intval', $item['candidate_ids'])));

        $select->execute(['person_key' => $key]);
        $existing = $select->fetch();

        if ($existing === false) {
            $insert->execute([
                'person_key' => $key,
                'last_name' => mb_substr((string) $person['last'], 0, 120),
                'first_name' => mb_substr((string) $person['first'], 0, 120),
                'mi' => (string) $person['mi'],
                'middle_name' => mb_substr((string) $person['middle'], 0, 120),
                'ext_name' => mb_substr((string) $person['ext'], 0, 20),
                'application_date' => $person['date'],
                'reason' => $item['status'],
                'candidate_ids' => $candidates,
                'source_filename' => $filename,
                'file_row' => (int) $person['row'],
                'created_by' => $userId,
            ]);
            $added++;
        } elseif ($existing['status'] === 'pending') {
            $update->execute([
                'reason' => $item['status'],
                'candidate_ids' => $candidates,
                'source_filename' => $filename,
                'file_row' => (int) $person['row'],
                'application_date' => $person['date'],
                'id' => (int) $existing['id'],
            ]);
            $updated++;
        } else {
            $skipped++;
        }
    }

    return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped];
}

/**
 * The confirm step of a preview, as ONE transaction: file the confident
 * applications and save the uncertain applicants for review, or neither.
 *
 * @return array{ok: bool, filed?: int, skipped?: int, review?: array, error?: string}
 */
function bulk_hge_apply_with_review(PDO $pdo, array $pairs, array $review, int $userId, string $filename): array
{
    try {
        $pdo->beginTransaction();
        $filedCounts = bulk_hge_insert_pairs($pdo, $pairs, $userId);
        $reviewCounts = bulk_hge_review_save($pdo, $review, $userId, $filename);
        $pdo->commit();
    } catch (\Throwable $e) {
        try {
            $pdo->rollBack();
        } catch (\Throwable $rollbackError) {
            error_log('bulk_hge_apply_with_review rollback failed: ' . $rollbackError->getMessage());
        }
        error_log('bulk_hge_apply_with_review failed: ' . $e->getMessage());

        return [
            'ok' => false,
            'error' => bulk_hge_review_missing_table_message($e)
                ?? 'THE APPLICATIONS COULD NOT BE FILED. NOTHING WAS CHANGED. PLEASE TRY AGAIN.',
        ];
    }

    return ['ok' => true, 'filed' => $filedCounts['filed'], 'skipped' => $filedCounts['skipped'], 'review' => $reviewCounts];
}

function bulk_hge_review_pending_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM bulk_hge_review_items WHERE status = 'pending'")->fetchColumn();
}

/**
 * One page of the items still waiting for an administrator, oldest first, each
 * with its possible saved records as they are RIGHT NOW (a record that was
 * deleted since is dropped; one filed since is flagged).
 *
 * @return array{total: int, items: array<int, array>}
 */
function bulk_hge_review_list(PDO $pdo, int $limit = 100, int $offset = 0): array
{
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);

    bulk_hge_review_close_settled($pdo);

    $rows = $pdo->query(
        "SELECT * FROM bulk_hge_review_items WHERE status = 'pending' ORDER BY id ASC LIMIT {$limit} OFFSET {$offset}"
    )->fetchAll();

    $ids = [];
    foreach ($rows as $row) {
        foreach ((array) json_decode((string) $row['candidate_ids'], true) as $id) {
            $ids[(int) $id] = true;
        }
    }

    $graduates = [];
    if ($ids !== []) {
        $in = implode(',', array_map('intval', array_keys($ids)));
        $stmt = $pdo->query(
            "SELECT g.id, g.graduate_name, g.course, g.latin_honor, g.graduation_year, s.school_name, s.campus, a.application_date AS applied_on
             FROM latin_honor_graduates g
             LEFT JOIN schools s ON s.id = g.school_id
             LEFT JOIN graduate_applications a ON a.graduate_id = g.id
             WHERE g.id IN ({$in})"
        );
        foreach ($stmt->fetchAll() as $g) {
            $graduates[(int) $g['id']] = [
                'id' => (int) $g['id'],
                'name' => (string) $g['graduate_name'],
                'school' => format_school_display_name((string) ($g['school_name'] ?? ''), $g['campus'] ?? null),
                'year' => (string) $g['graduation_year'],
                'course' => (string) $g['course'],
                'honor' => (string) $g['latin_honor'],
                'applied_on' => $g['applied_on'],
            ];
        }
    }

    $items = [];
    foreach ($rows as $row) {
        $candidates = [];
        foreach ((array) json_decode((string) $row['candidate_ids'], true) as $id) {
            if (isset($graduates[(int) $id])) {
                $candidates[] = $graduates[(int) $id];
            }
        }
        $person = [
            'last' => $row['last_name'], 'first' => $row['first_name'], 'mi' => $row['mi'],
            'middle' => $row['middle_name'], 'ext' => $row['ext_name'],
        ];
        $items[] = [
            'id' => (int) $row['id'],
            'name' => bulk_hge_person_display($person),
            'date' => $row['application_date'],
            'reason' => $row['reason'],
            'reason_label' => BULK_HGE_REVIEW_REASONS[$row['reason']] ?? strtoupper((string) $row['reason']),
            'reason_hint' => BULK_HGE_REVIEW_REASON_HINTS[$row['reason']] ?? '',
            'source_filename' => $row['source_filename'],
            'file_row' => (int) $row['file_row'],
            'candidates' => $candidates,
        ];
    }

    return ['total' => bulk_hge_review_pending_count($pdo), 'items' => $items];
}

/**
 * Files the item's application (with the date from the uploaded file) against
 * the chosen saved record and closes the item. The record must be one of the
 * item's possible matches.
 *
 * @return array{ok: bool, error?: string, graduate_name?: string, date?: string, person?: string}
 */
function bulk_hge_review_file(PDO $pdo, int $itemId, int $graduateId, int $userId): array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM bulk_hge_review_items WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $itemId]);
        $item = $stmt->fetch();

        if ($item === false || $item['status'] !== 'pending') {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'THIS ITEM WAS ALREADY RESOLVED.'];
        }

        $candidateIds = array_map('intval', (array) json_decode((string) $item['candidate_ids'], true));
        if (!in_array($graduateId, $candidateIds, true)) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'THAT RECORD IS NOT ONE OF THE POSSIBLE MATCHES FOR THIS APPLICANT.'];
        }

        $name = $pdo->prepare('SELECT graduate_name FROM latin_honor_graduates WHERE id = :id');
        $name->execute(['id' => $graduateId]);
        $graduateName = $name->fetchColumn();
        if ($graduateName === false) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'THAT GRADUATE RECORD NO LONGER EXISTS.'];
        }

        try {
            $pdo->prepare(
                'INSERT INTO graduate_applications (graduate_id, applied_by, application_date)
                 VALUES (:graduate_id, :applied_by, :application_date)'
            )->execute(['graduate_id' => $graduateId, 'applied_by' => $userId, 'application_date' => $item['application_date']]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $pdo->rollBack();

                return ['ok' => false, 'error' => 'THIS GRADUATE HAS ALREADY FILED AN APPLICATION. DISMISS THIS ITEM INSTEAD.'];
            }
            throw $e;
        }

        $pdo->prepare(
            "UPDATE bulk_hge_review_items
             SET status = 'filed', resolved_by = :resolved_by, resolved_at = :resolved_at, resolved_graduate_id = :graduate_id
             WHERE id = :id"
        )->execute([
            'resolved_by' => $userId,
            'resolved_at' => date('Y-m-d H:i:s'),
            'graduate_id' => $graduateId,
            'id' => $itemId,
        ]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'ok' => true,
        'graduate_name' => (string) $graduateName,
        'date' => (string) $item['application_date'],
        'person' => bulk_hge_person_display([
            'last' => $item['last_name'], 'first' => $item['first_name'], 'mi' => $item['mi'],
            'middle' => $item['middle_name'], 'ext' => $item['ext_name'],
        ]),
    ];
}

/**
 * Closes the item without filing anything ("none of these is the same person").
 *
 * @return array{ok: bool, error?: string}
 */
function bulk_hge_review_dismiss(PDO $pdo, int $itemId, int $userId): array
{
    $stmt = $pdo->prepare(
        "UPDATE bulk_hge_review_items
         SET status = 'dismissed', resolved_by = :resolved_by, resolved_at = :resolved_at
         WHERE id = :id AND status = 'pending'"
    );
    $stmt->execute(['resolved_by' => $userId, 'resolved_at' => date('Y-m-d H:i:s'), 'id' => $itemId]);

    return $stmt->rowCount() === 1 ? ['ok' => true] : ['ok' => false, 'error' => 'THIS ITEM WAS ALREADY RESOLVED.'];
}
