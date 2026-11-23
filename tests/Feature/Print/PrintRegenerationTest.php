<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Wm\WmPackage\Jobs\Print\GeneratePrintPdfJob;
use Wm\WmPackage\Jobs\Print\RegeneratePrintPdfsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAppRelationsInfoJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackOrderRelatedPoi;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\Services\Print\PrintService;
use Wm\WmPackage\Services\Print\PrintStatus;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config(['wm-package.shard_name' => 'testshard']);
    config(['cache.stores.redis.driver' => 'array']);
    Storage::fake('wmfe', ['url' => 'https://wmfe.test']);
    $this->regenApp = App::factory()->createQuietly();
});

function regenPoi(): EcPoi
{
    return EcPoi::factory()->createQuietly(['app_id' => test()->regenApp->id, 'properties' => ['name' => ['it' => 'Punto']]]);
}

function regenPut(EcTrack|EcPoi $model, string $locale): void
{
    $type = $model instanceof EcTrack ? 'ec-track' : 'ec-poi';
    Storage::disk('wmfe')->put("/testshard/{$model->app_id}/files/{$type}/{$model->id}/print-{$locale}.pdf", '%PDF');
}

function regenJobClasses(array $jobs): array
{
    return array_map(fn ($j) => $j::class, $jobs);
}

it('adds RegeneratePrintPdfsJob to publicationJobs only when the print domain is enabled', function () {
    $track = printSavedTrack(test()->regenApp);

    config(['wm-package.features.print.enabled' => false]);
    expect(regenJobClasses(app(EcTrackService::class)->publicationJobs($track)))->not->toContain(RegeneratePrintPdfsJob::class);

    config(['wm-package.features.print.enabled' => true]);
    $jobs = app(EcTrackService::class)->publicationJobs($track);
    expect(regenJobClasses($jobs))->toContain(RegeneratePrintPdfsJob::class)
        ->and(end($jobs))->toBeInstanceOf(RegeneratePrintPdfsJob::class);
});

it('dispatches one generation per existing locale', function () {
    config(['wm-package.features.print.enabled' => true]);
    Queue::fake();
    $track = printSavedTrack(test()->regenApp);
    regenPut($track, 'it');
    regenPut($track, 'en');

    (new RegeneratePrintPdfsJob($track))->handle(app(PrintService::class));

    Queue::assertPushed(GeneratePrintPdfJob::class, 2);
    Queue::assertPushed(GeneratePrintPdfJob::class, fn ($j) => $j->locale === 'it' && $j->model->is($track));
    Queue::assertPushed(GeneratePrintPdfJob::class, fn ($j) => $j->locale === 'en');
});

it('does not generate anything for an element that was never printed', function () {
    config(['wm-package.features.print.enabled' => true]);
    Queue::fake();

    (new RegeneratePrintPdfsJob(printSavedTrack(test()->regenApp)))->handle(app(PrintService::class));

    Queue::assertNothingPushed();
});

it('does nothing at run time when the domain is disabled and runs on the print queue', function () {
    config(['wm-package.features.print.enabled' => false, 'wm-package.features.print.queue' => 'stampa']);
    Queue::fake();
    $track = printSavedTrack(test()->regenApp);
    regenPut($track, 'it');

    $job = new RegeneratePrintPdfsJob($track);
    $job->handle(app(PrintService::class));

    Queue::assertNothingPushed();
    expect($job->queue)->toBe('stampa');
});

it('saving a poi regenerates its own pdfs and ends the chain of each linked track with the regeneration', function () {
    config(['wm-package.features.print.enabled' => true]);
    $poi = regenPoi();
    $track = printSavedTrack(test()->regenApp);
    $track->ecPois()->attach($poi->id);
    regenPut($poi, 'it');
    regenPut($track, 'en');

    Bus::fake();
    $poi = EcPoi::find($poi->id);
    $poi->name = ['it' => 'Nuovo nome'];
    $poi->save();

    // il POI: un solo job standalone, quello della traccia viaggia dentro la catena (non doppio)
    Bus::assertDispatchedTimes(RegeneratePrintPdfsJob::class, 1);
    // Dopo il commit: un Job partito prima del commit leggerebbe i dati vecchi (oc:8982, I2).
    Bus::assertDispatched(RegeneratePrintPdfsJob::class, fn ($j) => $j->model instanceof EcPoi && $j->model->is($poi) && $j->afterCommit === true);
    Bus::assertChained([
        UpdateEcTrackAwsJob::class,
        UpdateEcTrackAppRelationsInfoJob::class,
        UpdateEcTrackOrderRelatedPoi::class,
        RegeneratePrintPdfsJob::class,
    ]);
});

it('does not queue any regeneration when saving a poi with the domain disabled', function () {
    config(['wm-package.features.print.enabled' => false]);
    $poi = regenPoi();
    Bus::fake();

    $poi = EcPoi::find($poi->id);
    $poi->name = ['it' => 'Altro'];
    $poi->save();

    Bus::assertNotDispatched(RegeneratePrintPdfsJob::class);
});

it('deleting a track removes its print pdfs', function () {
    $track = printSavedTrack(test()->regenApp);
    regenPut($track, 'it');
    regenPut($track, 'en');
    $dir = "/testshard/{$track->app_id}/files/ec-track/{$track->id}";
    Storage::disk('wmfe')->assertExists("{$dir}/print-it.pdf");

    Bus::fake();
    $track->delete();

    Storage::disk('wmfe')->assertMissing("{$dir}/print-it.pdf");
    Storage::disk('wmfe')->assertMissing("{$dir}/print-en.pdf");
});

it('deleting a poi removes its print pdfs', function () {
    $poi = regenPoi();
    regenPut($poi, 'it');
    $path = "/testshard/{$poi->app_id}/files/ec-poi/{$poi->id}/print-it.pdf";
    Storage::disk('wmfe')->assertExists($path);

    Bus::fake();
    $poi->delete();

    Storage::disk('wmfe')->assertMissing($path);
});

it('releases the unique lock when processing starts, so a save during generation queues a new one', function () {
    // I lock di ShouldBeUnique non si liberano con flush() su ArrayStore (vedi .claude/rules/test.md).
    app('cache')->forgetDriver('redis');
    app('cache')->forgetDriver(config('cache.default'));
    Queue::fake();
    $poi = regenPoi();

    GeneratePrintPdfJob::dispatchFor($poi, 'it');
    GeneratePrintPdfJob::dispatchFor($poi, 'it');
    Queue::assertPushed(GeneratePrintPdfJob::class, 1);

    // Durante la generazione arriva un salvataggio che chiede di rigenerare: deve accodare.
    $this->mock(PrintService::class, function ($mock) use ($poi) {
        $mock->shouldReceive('generate')->once()->andReturnUsing(function () use ($poi) {
            GeneratePrintPdfJob::dispatchFor($poi, 'it');

            return 'https://wmfe.test/print-it.pdf';
        });
    });

    // Esecuzione del Job accodato dal vero CallQueuedHandler, con middleware e gestione del lock.
    $queued = Queue::pushed(GeneratePrintPdfJob::class)->first();
    $payload = json_encode([
        'uuid' => (string) Str::uuid(),
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'data' => ['commandName' => GeneratePrintPdfJob::class, 'command' => serialize($queued)],
    ]);
    (new SyncJob(app(), $payload, 'sync', 'print'))->fire();

    Queue::assertPushed(GeneratePrintPdfJob::class, 2);

    // Il nuovo Job ha ripreso il lock: un'altra richiesta mentre aspetta in coda non accoda.
    GeneratePrintPdfJob::dispatchFor($poi, 'it');
    Queue::assertPushed(GeneratePrintPdfJob::class, 2);
});

it('marks the element as changed when a term is attached or detached, so its print pdf is no longer fresh', function () {
    Queue::fake();
    config(['wm-package.features.print.enabled' => true]);
    $service = app(PrintService::class);
    $term = TaxonomyTerm::create(['identifier' => 'term-'.uniqid(), 'name' => ['it' => 'Percorribile']]);

    foreach ([printSavedTrack(test()->regenApp), regenPoi()] as $model) {
        // Dati di un'ora fa, PDF scritto adesso: fresco.
        $model::query()->whereKey($model->id)->toBase()->update(['updated_at' => now()->subHour()]);
        regenPut($model, 'it');
        expect($service->isFresh($model->fresh(), 'it'))->toBeTrue();

        // Il cambio di un termine scrive solo il pivot (come TrailStatusService::set()): la scheda non è più fresca.
        $this->travel(1)->minutes();
        $model->taxonomyTerms()->attach($term->id);
        expect($service->isFresh($model->fresh(), 'it'))->toBeFalse();

        regenPut($model, 'it');
        $this->travel(1)->minutes();
        $model->taxonomyTerms()->detach($term->id);
        expect($service->isFresh($model->fresh(), 'it'))->toBeFalse();
        $this->travelBack();
    }
});

it('does not touch the element on a term change when the print domain is disabled', function () {
    Queue::fake();
    config(['wm-package.features.print.enabled' => false]);
    $track = printSavedTrack(test()->regenApp);
    $track::query()->whereKey($track->id)->toBase()->update(['updated_at' => '2026-01-01 00:00:00']);
    $term = TaxonomyTerm::create(['identifier' => 'term-'.uniqid(), 'name' => ['it' => 'Termine']]);

    $track->taxonomyTerms()->attach($term->id);

    expect($track->fresh()->updated_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 00:00:00');
});

it('requeues instead of marking done when the element changed during the generation', function () {
    // I lock di ShouldBeUnique non si liberano con flush() su ArrayStore (vedi .claude/rules/test.md).
    app('cache')->forgetDriver('redis');
    app('cache')->forgetDriver(config('cache.default'));
    Queue::fake();
    $track = printSavedTrack(test()->regenApp);
    $track::query()->whereKey($track->id)->toBase()->update(['updated_at' => now()->subHour()]);
    $job = new GeneratePrintPdfJob($track->fresh(), 'it');

    // Mentre xelatex compila, un editor salva la traccia.
    $this->mock(PrintService::class, function ($mock) use ($track) {
        $mock->shouldReceive('generate')->once()->andReturnUsing(function () use ($track) {
            $track::query()->whereKey($track->id)->toBase()->update(['updated_at' => now()]);

            return 'https://wmfe.test/print-it.pdf';
        });
    });
    $job->handle(app(PrintService::class));

    Queue::assertPushed(GeneratePrintPdfJob::class, 1);
    expect(PrintStatus::get($track, 'it'))->toBe(['status' => 'queued']);
});

it('marks done when the element did not change during the generation', function () {
    Queue::fake();
    $track = printSavedTrack(test()->regenApp);
    $this->mock(PrintService::class, function ($mock) {
        $mock->shouldReceive('generate')->once()->andReturn('https://wmfe.test/print-it.pdf');
    });

    (new GeneratePrintPdfJob($track->fresh(), 'it'))->handle(app(PrintService::class));

    Queue::assertNothingPushed();
    expect(PrintStatus::get($track, 'it'))->toBe(['status' => 'done', 'url' => 'https://wmfe.test/print-it.pdf']);
});
