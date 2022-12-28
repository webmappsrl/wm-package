<?php

namespace Tests\Unit\Providers;


use Mockery;
use Mockery\MockInterface;
use Wm\WmPackage\Model\User;
use Wm\WmPackage\Tests\TestCase;
use Illuminate\Testing\Fluent\AssertableJson;

class ApiRouteTest extends TestCase
{
  /**
   * A basic feature test example.
   *
   * @return void
   */
  public function test_login_api_route_existence()
  {
    // $params = ['email' => '123', 'password' => '123'];
    // //User::shouldReceive('create')->once()->andReturn(new User($params));
    // //User::shouldReceive('createToken')->once()->andReturn('token');

    // $this->instance(
    //   Wm\WmPackage\Model\User::class,
    //   function (MockInterface $mock) use ($params) {
    //     $mock->shouldReceive('create');
    //   }
    // );

    $response = $this->postJson('/api/login', []);
    $response->assertJson(
      fn (AssertableJson $json) =>
      $json->hasAll(['message', 'errors'])
    );
  }

  /**
   * A basic feature test example.
   *
   * @return void
   */
  public function test_user_api_route_existence()
  {
    $response = $this->getJson('/api/user', []);
    $response->assertJson(
      fn (AssertableJson $json) =>
      $json->hasAll(['message'])
    );
  }

  /**
   * A basic feature test example.
   *
   * @return void
   */
  public function test_logout_api_route_existence()
  {
    $response = $this->postJson('/api/logout', []);
    $response->assertJson(
      fn (AssertableJson $json) =>
      $json->hasAll(['message'])
    );
  }

  /**
   * A basic feature test example.
   *
   * @return void
   */
  public function test_register_api_route_existence()
  {
    $this->instance(
      'abilities',
      \Laravel\Sanctum\Http\Middleware\CheckAbilities::class
    );


    $response = $this->postJson('/api/register', []);
    $response->assertJson(
      fn (AssertableJson $json) =>
      $json->hasAll(['message'])
    );
  }
}
