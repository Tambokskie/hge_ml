<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;
use Tests\TestDb;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/schools.php';

/** The SCHOOLS card on admin/upload.php: schools that have uploads, with what its details modal shows. */
class SchoolsWithUploadsTest extends TestCase
{
    protected function setUp(): void
    {
        TestDb::resetSchema();
        $pdo = TestDb::pdo();
        $pdo->exec("INSERT INTO users (name, username, email, password, role) VALUES ('Admin', 'admin', 'a@a.com', 'x', 'administrator')");
        $pdo->exec(
            "INSERT INTO schools (school_name, address, campus, status) VALUES
             ('ZETA COLLEGE', 'ZETA CITY', '', 'active'),
             ('ALPHA UNIVERSITY', 'ALPHA, LEYTE', 'MAIN', 'disabled'),
             ('NO UPLOADS COLLEGE', 'NOWHERE', '', 'active')"
        );
        $pdo->exec(
            "INSERT INTO latin_honor_uploads (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
             VALUES (1, '2025', 'z.pdf', 'z.pdf', '/x/z.pdf', 10, SHA2('z',256), 1, 1),
                    (2, '2025', 'a1.pdf', 'a1.pdf', '/x/a1.pdf', 10, SHA2('a1',256), 1, 1),
                    (2, '2026', 'a2.pdf', 'a2.pdf', '/x/a2.pdf', 10, SHA2('a2',256), 1, 1)"
        );
    }

    public function testListsOnlySchoolsWithUploadsOnceEachInNameOrder(): void
    {
        $schools = get_schools_with_uploads(TestDb::pdo());

        $this->assertSame(['ALPHA UNIVERSITY', 'ZETA COLLEGE'], array_column($schools, 'school_name'));
    }

    public function testEachSchoolCarriesEverythingTheDetailsModalShows(): void
    {
        $alpha = get_schools_with_uploads(TestDb::pdo())[0];

        $this->assertSame(['ALPHA UNIVERSITY', 'ALPHA, LEYTE', 'MAIN', 'disabled'], [$alpha['school_name'], $alpha['address'], $alpha['campus'], $alpha['status']]);
        $this->assertGreaterThan(0, (int) $alpha['id']);
    }
}
