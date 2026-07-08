<?php

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;

beforeEach(function () {
    config(['wm-package.export.token' => 'test-token']);
});

it('returns 403 when export token is not configured on the instance', function () {
    config(['wm-package.export.token' => null]);

    $this->getJson('/api/v1/export/apps', ['Authorization' => 'Bearer test-token'])
        ->assertStatus(403);
});

it('returns 401 when the bearer token is missing or wrong', function () {
    $this->getJson('/api/v1/export/apps')->assertStatus(401);

    $this->getJson('/api/v1/export/apps', ['Authorization' => 'Bearer wrong'])
        ->assertStatus(401);
});

it('returns 200 with the right token', function () {
    App::factory()->createQuietly();

    $this->getJson('/api/v1/export/apps', ['Authorization' => 'Bearer test-token'])
        ->assertOk();
});

it('lists apps with the v1 contract fields', function () {
    $user = User::factory()->create(['email' => 'owner@example.org', 'name' => 'Owner']);
    App::factory()->createQuietly(['user_id' => $user->id, 'customer_name' => 'ACME']);

    $this->getJson('/api/v1/export/apps', ['Authorization' => 'Bearer test-token'])
        ->assertOk()
        ->assertJsonStructure([
            'data' => [[
                'id', 'sku', 'name', 'customer_name', 'api',
                'ios_store_link', 'android_store_link',
                'default_language', 'available_languages', 'welcome',
                'dashboard_show', 'author_name', 'author_email',
                'created_at', 'updated_at',
            ]],
            'links', 'meta',
        ])
        ->assertJsonPath('data.0.author_email', 'owner@example.org');
});

it('filters the list with updated_after', function () {
    $old = App::factory()->createQuietly();
    $old->timestamps = false;
    $old->forceFill(['updated_at' => now()->subDays(10)])->saveQuietly();

    App::factory()->createQuietly(); // updated_at = now

    $this->getJson('/api/v1/export/apps?updated_after='.urlencode(now()->subDay()->toIso8601String()), [
        'Authorization' => 'Bearer test-token',
    ])->assertOk()->assertJsonCount(1, 'data');
});

it('rejects a malformed updated_after with 422', function () {
    $this->getJson('/api/v1/export/apps?updated_after=not-a-date', [
        'Authorization' => 'Bearer test-token',
    ])->assertStatus(422);
});

it('shows a single app', function () {
    $app = App::factory()->createQuietly();

    $this->getJson('/api/v1/export/apps/'.$app->id, ['Authorization' => 'Bearer test-token'])
        ->assertOk()
        ->assertJsonPath('data.id', $app->id);
});

it('returns 404 for a missing app', function () {
    $this->getJson('/api/v1/export/apps/999999', ['Authorization' => 'Bearer test-token'])
        ->assertStatus(404);
});
