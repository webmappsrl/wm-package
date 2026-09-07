<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Http\Clients\Osm2caiClient;
use Wm\WmPackage\Jobs\TaxonomyWhere\FetchOsm2caiSectorGeometryJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\FetchTaxonomyWhereGeometryJob;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Nova\Actions\RetryTaxonomyWhereGeometryFetch;

function runRetryAction(iterable $models): void
{
    (new RetryTaxonomyWhereGeometryFetch)->handle(
        new ActionFields(collect(), collect()),
        collect($models)
    );
}

it('dispatches the osm2cai job for sectors, which have no osmfeatures id', function () {
    Queue::fake();

    $sector = TaxonomyWhere::create([
        'name' => ['it' => 'ZNUC2'],
        'properties' => ['source' => 'osm2cai', 'osm2cai_id' => 753],
    ]);

    runRetryAction([$sector]);

    Queue::assertPushed(FetchOsm2caiSectorGeometryJob::class);
    Queue::assertNotPushed(FetchTaxonomyWhereGeometryJob::class);
});

it('dispatches the osmfeatures job for admin areas', function () {
    Queue::fake();

    $province = TaxonomyWhere::create([
        'name' => ['it' => 'Cagliari'],
        'properties' => [
            'source' => 'osmfeatures',
            'osmfeatures_id' => 'R276369',
            'admin_level' => 6,
        ],
    ]);

    runRetryAction([$province]);

    Queue::assertPushed(FetchTaxonomyWhereGeometryJob::class);
    Queue::assertNotPushed(FetchOsm2caiSectorGeometryJob::class);
});

it('skips records whose source has no geometry fetcher', function () {
    Queue::fake();

    $legacy = TaxonomyWhere::create([
        'name' => ['it' => 'Area F (Nord)'],
        'properties' => ['source' => 'geohub_conf_32'],
    ]);

    runRetryAction([$legacy]);

    Queue::assertNothingPushed();
});

it('routes each record of a mixed selection to its own job', function () {
    Queue::fake();

    $sector = TaxonomyWhere::create([
        'name' => ['it' => 'ZCAD7'],
        'properties' => ['source' => 'osm2cai', 'osm2cai_id' => 623],
    ]);
    $province = TaxonomyWhere::create([
        'name' => ['it' => 'Nuoro'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R39979'],
    ]);

    runRetryAction([$sector, $province]);

    Queue::assertPushed(FetchOsm2caiSectorGeometryJob::class, 1);
    Queue::assertPushed(FetchTaxonomyWhereGeometryJob::class, 1);
});

it('fails the sector geometry job when osm2cai rate limits, instead of reporting success', function () {
    Http::fake([
        'osm2cai.cai.it/api/v3/sectors/*' => Http::response(['message' => 'Too Many Attempts.'], 429),
    ]);

    $sector = TaxonomyWhere::create([
        'name' => ['it' => 'ZNUE4'],
        'properties' => ['source' => 'osm2cai', 'osm2cai_id' => 751],
    ]);

    expect(fn () => (new FetchOsm2caiSectorGeometryJob($sector->id))->handle(app(Osm2caiClient::class)))
        ->toThrow(Exception::class, 'HTTP 429');

    expect($sector->fresh()->properties)->not->toHaveKey('full_code');
});

it('retries the sector geometry job with a progressive backoff', function () {
    $job = new FetchOsm2caiSectorGeometryJob(1);

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 60, 120]);
});
