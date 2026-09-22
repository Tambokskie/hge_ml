<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

class AuthCsrfAndRolesTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testCsrfTokenIsStableAcrossCalls(): void
    {
        $first = csrf_token();
        $second = csrf_token();
        $this->assertSame($first, $second);
        $this->assertGreaterThanOrEqual(32, strlen($first));
    }

    public function testCsrfVerifyAcceptsMatchingTokenAndRejectsOthers(): void
    {
        $token = csrf_token();
        $this->assertTrue(csrf_verify($token));
        $this->assertFalse(csrf_verify('wrong-token'));
        $this->assertFalse(csrf_verify(null));
    }

    public function testUserHasRoleMatchesExactRole(): void
    {
        $user = ['role' => 'user'];
        $this->assertTrue(user_has_role($user, 'user'));
        $this->assertFalse(user_has_role($user, 'administrator'));
    }

    public function testAdministratorSatisfiesAnyRoleCheck(): void
    {
        $admin = ['role' => 'administrator'];
        $this->assertTrue(user_has_role($admin, 'user'));
        $this->assertTrue(user_has_role($admin, 'administrator'));
    }

    public function testUserHasRoleRejectsNullUser(): void
    {
        $this->assertFalse(user_has_role(null, 'user'));
    }

    public function testCurrentUserReturnsNullWhenNotLoggedIn(): void
    {
        $this->assertNull(current_user());
    }

    public function testHasStepUpIsFalseWithNoGrant(): void
    {
        $this->assertFalse(has_step_up('ocr_courses'));
    }

    public function testGrantStepUpSatisfiesHasStepUpForSamePurpose(): void
    {
        grant_step_up('ocr_courses');

        $this->assertTrue(has_step_up('ocr_courses'));
    }

    public function testHasStepUpRejectsDifferentPurpose(): void
    {
        grant_step_up('ocr_courses');

        $this->assertFalse(has_step_up('some_other_purpose'));
    }

    public function testHasStepUpRejectsExpiredGrant(): void
    {
        grant_step_up('ocr_courses', -1);

        $this->assertFalse(has_step_up('ocr_courses'));
    }

    public function testStepUpDestinationsIncludesOcrCourses(): void
    {
        $destinations = step_up_destinations();

        $this->assertArrayHasKey('ocr_courses', $destinations);
        $this->assertSame('ocr_courses', $destinations['ocr_courses']['purpose']);
        $this->assertSame('/admin/ocr_courses.php', $destinations['ocr_courses']['path']);
    }
}
