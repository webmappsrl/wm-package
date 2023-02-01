<?php

namespace Tests\Unit\Providers;

use Illuminate\Testing\Fluent\AssertableJson;
use Wm\WmPackage\Tests\TestCase;

class ApiRouteTest extends TestCase
{
    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_login_api_route_existence()
    {
        $response = $this->postJson('/api/wm/login', []);
        $response->assertJson(
            fn (AssertableJson $json) => $json->hasAll(['message', 'errors'])
        );
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_user_api_route_existence()
    {
        $response = $this->getJson('/api/wm/user', []);
        $response->assertJson(
            fn (AssertableJson $json) => $json->hasAll(['message'])
        );
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_logout_api_route_existence()
    {
        $response = $this->postJson('/api/wm/logout', []);
        $response->assertJson(
            fn (AssertableJson $json) => $json->hasAll(['message'])
        );
    }
}
