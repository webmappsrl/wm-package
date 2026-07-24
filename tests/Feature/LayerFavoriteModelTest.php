<?php

declare(strict_types=1);

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

it('allows a user to favorite and unfavorite a layer', function () {
    $app = App::factory()->createQuietly();
    $user = User::factory()->create(['app_id' => $app->id]);
    $layer = Layer::factory()->create(['app_id' => $app->id]);

    expect($layer->isFavorited($user->id))->toBeFalse();

    $layer->toggleFavorite($user->id);
    expect($layer->fresh()->isFavorited($user->id))->toBeTrue();

    $layer->toggleFavorite($user->id);
    expect($layer->fresh()->isFavorited($user->id))->toBeFalse();
});
