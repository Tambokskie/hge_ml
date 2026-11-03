<?php

namespace Tests;

use PDO;

class TestDb
{
    // Deliberately not "hge_masterlist_test" (the original name): that schema
    // picked up permanent InnoDB data-dictionary corruption on this machine
    // after an unclean MariaDB shutdown — orphaned entries in
    // information_schema.INNODB_SYS_TABLESPACES that survive dropping the
    // database's files from disk, and crash the server outright on DROP
    // TABLE/DROP DATABASE (even under --innodb-force-recovery=1). Renaming
    // sidesteps it entirely rather than risking further server-wide InnoDB
    // surgery for a disposable schema. If this one ever gets into the same
    // state, rename again rather than trying to repair it in place.
    private const DB_NAME = 'hge_masterlist_test2';

    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('CREATE DATABASE IF NOT EXISTS ' . self::DB_NAME . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            self::$pdo->exec('USE ' . self::DB_NAME);
        }

        return self::$pdo;
    }

    public static function resetSchema(): void
    {
        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['bulk_hge_review_items', 'recycle_bin_items', 'graduate_recycle_bin', 'graduate_duplicate_findings', 'graduate_applications', 'activity_log', 'ocr_course_keywords', 'app_settings', 'latin_honor_graduates', 'extraction_review_drafts', 'latin_honor_uploads', 'schools', 'users'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $sql = file_get_contents(__DIR__ . '/../database/schema.sql');
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || stripos($statement, 'CREATE TABLE') !== 0) {
                continue;
            }
            $pdo->exec($statement);
        }
    }

    public static function truncateAll(): void
    {
        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['bulk_hge_review_items', 'recycle_bin_items', 'graduate_recycle_bin', 'graduate_duplicate_findings', 'graduate_applications', 'activity_log', 'ocr_course_keywords', 'app_settings', 'latin_honor_graduates', 'extraction_review_drafts', 'latin_honor_uploads', 'schools', 'users'] as $table) {
            $pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
