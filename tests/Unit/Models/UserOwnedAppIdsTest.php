<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;

uses(TestCase::class, DatabaseTransactions::class);

it('returns the ids of the apps owned by the user', function () {
    $user = User::factory()->create();
    $app1 = App::factory()->createQuietly(['user_id' => $user->id]);
    $app2 = App::factory()->createQuietly(['user_id' => $user->id]);

    expect($user->ownedAppIds()->all())->toEqualCanonicalizing([$app1->id, $app2->id]);
});

it('returns an empty collection when the user owns no app', function () {
    $user = User::factory()->create();

    expect($user->ownedAppIds())->toBeEmpty();
});
