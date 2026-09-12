<?php

namespace Tests\Config;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../config/database.php';

class DatabaseTest extends TestCase
{
    public function testDefaultConfigPointsAtXamppLocal(): void
    {
        putenv('DB_HOST');
        putenv('DB_NAME');
        putenv('DB_USER');
        putenv('DB_PASS');

        $config = get_db_config();

        $this->assertSame('127.0.0.1', $config['host']);
        $this->assertSame('hge_masterlist', $config['name']);
        $this->assertSame('root', $config['user']);
        $this->assertSame('', $config['pass']);
    }

    public function testConfigHonorsEnvironmentOverrides(): void
    {
        putenv('DB_NAME=hge_masterlist_test');
        $config = get_db_config();
        $this->assertSame('hge_masterlist_test', $config['name']);
        putenv('DB_NAME');
    }

    public function testConnectPdoConnectsWithGoodConfig(): void
    {
        $pdo = connect_pdo([
            'host' => '127.0.0.1',
            'name' => 'hge_masterlist_test',
            'user' => 'root',
            'pass' => '',
        ]);

        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertEquals('1', $pdo->query('SELECT 1')->fetchColumn());
    }

    public function testConnectPdoThrowsOnUnknownDatabase(): void
    {
        $this->expectException(\PDOException::class);
        connect_pdo([
            'host' => '127.0.0.1',
            'name' => 'this_database_does_not_exist',
            'user' => 'root',
            'pass' => '',
        ]);
    }
}
