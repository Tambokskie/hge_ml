<?php

namespace Tests;

use PDO;

class TestDb
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('CREATE DATABASE IF NOT EXISTS hge_masterlist_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            self::$pdo->exec('USE hge_masterlist_test');
        }

        return self::$pdo;
    }

    public static function resetSchema(): void
    {
        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['graduate_applications', 'activity_log', 'latin_honor_graduates', 'latin_honor_uploads', 'schools', 'users'] as $table) {
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
        foreach (['graduate_applications', 'activity_log', 'latin_honor_graduates', 'latin_honor_uploads', 'schools', 'users'] as $table) {
            $pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
