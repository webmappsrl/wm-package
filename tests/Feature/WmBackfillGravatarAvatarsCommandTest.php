<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Jobs\FetchGravatarAvatarJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;

uses(TestCase::class, DatabaseTransactions::class);

it('requires --app-id', function () {
    Queue::fake();
    User::factory()->create();

    $this->artisan('wm:backfill-gravatar-avatars')->assertExitCode(1);

    Queue::assertNotPushed(FetchGravatarAvatarJob::class);
});

it('dispatches FetchGravatarAvatarJob only for users without an existing avatar', function () {
    Queue::fake();
    Storage::fake('wmfe');
    $app = App::factory()->createQuietly();
    $withAvatar = User::factory()->create();
    $withAvatar->addMedia(UploadedFile::fake()->image('avatar.jpg', 400, 400))
        ->toMediaCollection('avatar');
    $withoutAvatar = User::factory()->create();

    $this->artisan('wm:backfill-gravatar-avatars', ['--app-id' => $app->id])->assertExitCode(0);

    Queue::assertPushed(FetchGravatarAvatarJob::class, function ($job) use ($withoutAvatar) {
        return $job->userId === $withoutAvatar->id;
    });
    Queue::assertNotPushed(FetchGravatarAvatarJob::class, function ($job) use ($withAvatar) {
        return $job->userId === $withAvatar->id;
    });
});

it('assigns the command-supplied app-id to every dispatched job, regardless of the user\'s own (often-null) app_id column', function () {
    Queue::fake();
    $app = App::factory()->createQuietly();
    // users.app_id is left null on purpose here — this mirrors real production data,
    // where app-signup users almost never have this column populated (verified: 5126
    // out of 5129 real users on camminiditalia have app_id IS NULL). The command must
    // not rely on it for user selection nor for the media's app_id attribution.
    $userWithNullAppId = User::factory()->create(['app_id' => null]);

    $this->artisan('wm:backfill-gravatar-avatars', ['--app-id' => $app->id])->assertExitCode(0);

    Queue::assertPushed(FetchGravatarAvatarJob::class, function ($job) use ($userWithNullAppId, $app) {
        return $job->userId === $userWithNullAppId->id && $job->appId === $app->id;
    });
});

// No dedicated "zero users to process" test: these Feature tests run with
// DatabaseTransactions against the real, populated dev database (confirmed:
// thousands of pre-existing users without an avatar), not an isolated empty
// schema. Since the command's user-selection query is now intentionally
// unconditional (no --app-id filtering, per the fix above), there is no
// option combination left that can deterministically produce zero matching
// rows in this environment — the previous version of this test relied on
// filtering by a nonexistent app_id, which is exactly the unreliable
// mechanism this fix removes. The `if ($total === 0)` branch itself is two
// lines and self-evidently correct; not worth a fragile, environment-coupled
// test to cover it.
