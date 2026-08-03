<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Fields\UserAvatar;

uses(TestCase::class, DatabaseTransactions::class);

function resolveUserAvatarValue(User $user): string
{
    $field = UserAvatar::make();
    $request = NovaRequest::create('/');
    $field->resolveForDisplay($user);

    return $field->value;
}

it('shows the real avatar_url when the user has an uploaded/Gravatar-fetched avatar', function () {
    Storage::fake('wmfe');
    $user = User::factory()->create(['email' => 'nova-avatar-test@example.com']);
    $user->addMedia(UploadedFile::fake()->image('avatar.jpg', 400, 400))
        ->toMediaCollection('avatar');

    $value = resolveUserAvatarValue($user->fresh());

    expect($value)->toBe($user->fresh()->avatar_url)
        ->and($value)->not->toContain('gravatar.com');
});

it('falls back to the Gravatar URL computed from the email when no avatar is set', function () {
    $user = User::factory()->create(['email' => 'nova-fallback-test@example.com']);

    $value = resolveUserAvatarValue($user);

    $expectedHash = md5(strtolower('nova-fallback-test@example.com'));
    expect($value)->toBe("https://www.gravatar.com/avatar/{$expectedHash}?s=300");
});
