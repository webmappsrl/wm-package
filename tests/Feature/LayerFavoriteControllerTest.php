<?php

declare(strict_types=1);

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

// Base TestCase (Wm\WmPackage\Tests\TestCase, with RefreshDatabase) is applied globally by
// tests/Pest.php — no explicit uses() needed here.

beforeEach(function () {
    // Without this, `actingAs($user, 'api')` throws JWTException("Secret is not set.") as soon
    // as the jwt guard tries to resolve a key — same fix already used by
    // ShareStoryImageControllerTest.
    $this->withoutMiddleware('auth.jwt');
    $this->artisan('jwt:secret --always-no');
});

it('rejects an unauthenticated toggle request', function () {
    $app = App::factory()->createQuietly();
    $layer = Layer::factory()->create(['app_id' => $app->id]);

    $response = $this->postJson("/api/layer/favorite/toggle/{$layer->id}");

    $response->assertStatus(401);
});

it('adds a layer to favorites and is idempotent on repeated add', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $layer = Layer::factory()->create(['app_id' => $app->id]);
    $this->actingAs($user, 'api');

    $response = $this->postJson("/api/layer/favorite/add/{$layer->id}");
    $response->assertOk()->assertJson(['favorite' => true]);

    $response = $this->postJson("/api/layer/favorite/add/{$layer->id}");
    $response->assertOk()->assertJson(['favorite' => true]);

    expect($layer->fresh()->isFavorited($user->id))->toBeTrue();
});

it('removes a layer from favorites and is idempotent on repeated remove', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $layer = Layer::factory()->create(['app_id' => $app->id]);
    $layer->toggleFavorite($user->id);
    $this->actingAs($user, 'api');

    $response = $this->postJson("/api/layer/favorite/remove/{$layer->id}");
    $response->assertOk()->assertJson(['favorite' => false]);

    $response = $this->postJson("/api/layer/favorite/remove/{$layer->id}");
    $response->assertOk()->assertJson(['favorite' => false]);

    expect($layer->fresh()->isFavorited($user->id))->toBeFalse();
});

it('toggles a layer favorite state back and forth', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $layer = Layer::factory()->create(['app_id' => $app->id]);
    $this->actingAs($user, 'api');

    $response = $this->postJson("/api/layer/favorite/toggle/{$layer->id}");
    $response->assertOk()->assertJson(['favorite' => true]);

    $response = $this->postJson("/api/layer/favorite/toggle/{$layer->id}");
    $response->assertOk()->assertJson(['favorite' => false]);
});

it('lists the authenticated user favorite layers with a lightweight payload', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $favorited = Layer::factory()->create(['app_id' => $app->id, 'name' => ['it' => 'Cammino preferito', 'en' => 'Favorite path']]);
    $notFavorited = Layer::factory()->create(['app_id' => $app->id]);
    $favorited->toggleFavorite($user->id);
    $this->actingAs($user, 'api');

    $response = $this->getJson('/api/layer/favorite/list');

    $response->assertOk();
    $favorites = $response->json('favorites');
    expect($favorites)->toHaveCount(1);
    expect($favorites[0]['id'])->toBe($favorited->id);
    expect($favorites[0]['title']['it'])->toBe('Cammino preferito');
    expect($favorites[0])->toHaveKeys(['id', 'title', 'feature_image', 'logo_image', 'style']);
    expect(collect($favorites)->pluck('id'))->not->toContain($notFavorited->id);
});
