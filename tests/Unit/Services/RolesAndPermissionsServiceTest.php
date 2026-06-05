<?php

namespace Wm\WmPackage\Tests\Unit\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Mockery;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Tests\TestCase;

class RolesAndPermissionsServiceTest extends TestCase
{
    // --- allowsEmail ---

    public function test_allows_email_returns_false_for_null(): void
    {
        $this->assertFalse(RolesAndPermissionsService::allowsEmail(null));
    }

    public function test_allows_email_returns_false_for_empty_string(): void
    {
        $this->assertFalse(RolesAndPermissionsService::allowsEmail(''));
    }

    public function test_allows_email_returns_false_when_not_in_allowlist(): void
    {
        config(['wm-package.super_admin_emails' => ['allowed@webmapp.it']]);

        $this->assertFalse(RolesAndPermissionsService::allowsEmail('other@example.com'));
    }

    public function test_allows_email_returns_true_when_in_allowlist(): void
    {
        config(['wm-package.super_admin_emails' => ['allowed@webmapp.it']]);

        $this->assertTrue(RolesAndPermissionsService::allowsEmail('allowed@webmapp.it'));
    }

    public function test_allows_email_uses_default_fallback_when_config_key_is_null(): void
    {
        config(['wm-package.super_admin_emails' => null]);

        $this->assertTrue(RolesAndPermissionsService::allowsEmail('team@webmapp.it'));
        $this->assertFalse(RolesAndPermissionsService::allowsEmail('other@example.com'));
    }

    // --- allowsUser ---

    public function test_allows_user_returns_false_for_null(): void
    {
        $this->assertFalse(RolesAndPermissionsService::allowsUser(null));
    }

    public function test_allows_user_returns_false_when_email_property_is_not_a_string(): void
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->email = new \stdClass;

        $this->assertFalse(RolesAndPermissionsService::allowsUser($user));
    }

    public function test_allows_user_returns_true_for_allowed_email(): void
    {
        config(['wm-package.super_admin_emails' => ['dev@webmapp.it']]);

        $user = Mockery::mock(Authenticatable::class);
        $user->email = 'dev@webmapp.it';

        $this->assertTrue(RolesAndPermissionsService::allowsUser($user));
    }

    public function test_allows_user_returns_false_for_not_allowed_email(): void
    {
        config(['wm-package.super_admin_emails' => ['dev@webmapp.it']]);

        $user = Mockery::mock(Authenticatable::class);
        $user->email = 'stranger@example.com';

        $this->assertFalse(RolesAndPermissionsService::allowsUser($user));
    }

    // --- allows (Request) ---

    public function test_allows_returns_false_for_null_request(): void
    {
        $this->assertFalse(RolesAndPermissionsService::allows(null));
    }

    public function test_allows_returns_false_when_request_has_no_authenticated_user(): void
    {
        $request = Request::create('/');

        $this->assertFalse(RolesAndPermissionsService::allows($request));
    }
}
