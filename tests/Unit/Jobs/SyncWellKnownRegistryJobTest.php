<?php

namespace Tests\Unit\Jobs;

use Wm\WmPackage\Jobs\SyncWellKnownRegistryJob;
use Wm\WmPackage\Services\WellKnownRegistryService;
use Wm\WmPackage\Tests\TestCase;

class SyncWellKnownRegistryJobTest extends TestCase
{
    /** @test */
    public function add_action_calls_add_app_entry_on_the_service()
    {
        $this->mock(WellKnownRegistryService::class, function ($mock) {
            $mock->shouldReceive('addAppEntry')->once()->with('it.webmapp.testapp', 'AA:BB', null);
            $mock->shouldReceive('removeAppEntry')->never();
        });

        (new SyncWellKnownRegistryJob('add', 'it.webmapp.testapp', 'AA:BB'))->handle();
    }

    /** @test */
    public function add_action_passes_through_a_custom_apple_team_id()
    {
        $this->mock(WellKnownRegistryService::class, function ($mock) {
            $mock->shouldReceive('addAppEntry')->once()->with('it.webmapp.testapp', 'AA:BB', 'CUSTOMTEAM1');
        });

        (new SyncWellKnownRegistryJob('add', 'it.webmapp.testapp', 'AA:BB', 'CUSTOMTEAM1'))->handle();
    }

    /** @test */
    public function remove_action_calls_remove_app_entry_on_the_service()
    {
        $this->mock(WellKnownRegistryService::class, function ($mock) {
            $mock->shouldReceive('removeAppEntry')->once()->with('it.webmapp.testapp', null);
            $mock->shouldReceive('addAppEntry')->never();
        });

        (new SyncWellKnownRegistryJob('remove', 'it.webmapp.testapp'))->handle();
    }
}
