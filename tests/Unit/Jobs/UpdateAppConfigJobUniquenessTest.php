<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;

it('is declared unique, so that uniqueId() is actually honoured', function () {
    expect(new UpdateAppConfigJob(1))->toBeInstanceOf(ShouldBeUnique::class);
});

it('scopes uniqueness per app', function () {
    expect((new UpdateAppConfigJob(1))->uniqueId())->toBe('update-app-config-1')
        ->and((new UpdateAppConfigJob(2))->uniqueId())->toBe('update-app-config-2');
});
