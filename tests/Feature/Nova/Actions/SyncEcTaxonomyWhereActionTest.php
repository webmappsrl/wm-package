<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Laravel\Nova\Fields\ActionFields;
use Tests\TestCase;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob;
use Wm\WmPackage\Nova\Actions\SyncEcTaxonomyWhereAction;

// wm-package/tests/Feature is outside the path bound by the consumer's tests/Pest.php
// (`pest()->extend(Tests\TestCase::class)->in('Feature')`), so the TestCase must be declared
// explicitly here, otherwise the app is never booted (see wm-package .claude/rules/test.md) and
// Bus::fake() cannot resolve the QueueingDispatcher.
uses(TestCase::class, DatabaseTransactions::class);

it('dispatches SyncTaxonomyWhereJob in queue and returns an immediate message', function () {
    Bus::fake();

    $action = new SyncEcTaxonomyWhereAction;
    $result = $action->handle(new ActionFields(collect(), collect()), collect());

    Bus::assertDispatched(SyncTaxonomyWhereJob::class);
    expect((string) $result['message'])->toContain('avviata');
});
