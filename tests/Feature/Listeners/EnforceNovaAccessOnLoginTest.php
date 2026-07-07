<?php

namespace Wm\WmPackage\Tests\Feature\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Tests\TestCase;

class EnforceNovaAccessOnLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
    }

    public function test_login_is_rejected_for_user_without_access_nova(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $user->assignRole('Guest');

        $this->expectException(ValidationException::class);

        Auth::guard('web')->login($user);
        event(new Login('web', $user, false));

        $this->assertFalse(Auth::guard('web')->check());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_login_succeeds_for_user_with_access_nova(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Editor');

        Auth::guard('web')->login($user);
        event(new Login('web', $user, false));

        $this->assertTrue(Auth::guard('web')->check());
    }

    public function test_login_event_on_api_guard_is_ignored(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Guest');

        // Non deve lanciare nulla: il listener ignora i guard diversi da 'web'.
        event(new Login('api', $user, false));

        $this->assertTrue(true);
    }
}
