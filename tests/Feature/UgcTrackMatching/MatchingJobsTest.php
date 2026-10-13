<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\MatchEcTrackToUgcTracksJob;
use Wm\WmPackage\Jobs\MatchUgcTrackToEcTracksJob;
use Wm\WmPackage\Jobs\RematchAppUgcTracksJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrackMatchingRun;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

it('il job per traccia gira sulla coda dedicata ed è unico per traccia', function () {
    $job = new MatchUgcTrackToEcTracksJob(42);

    expect($job->queue)->toBe('ugc-track-matching');
    expect($job->uniqueId())->toBe('42');
    expect($job->timeout)->toBe(85);
    expect($job->uniqueFor)->toBe(3600);
    expect($job->maxExceptions)->toBe(3);
    expect($job->retryUntil())->toBeInstanceOf(DateTime::class);
});

it('il job per EcTrack gira sulla coda dedicata ed è unico per EcTrack', function () {
    $job = new MatchEcTrackToUgcTracksJob(7);

    expect($job->queue)->toBe('ugc-track-matching');
    expect($job->uniqueId())->toBe('7');
    expect($job->timeout)->toBe(85);
    expect($job->uniqueFor)->toBe(3600);
    expect($job->retryUntil())->toBeInstanceOf(DateTime::class);
});

it('se il job fallisce lo stato della traccia diventa failed con il motivo', function () {
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');

    (new MatchUgcTrackToEcTracksJob($ugc))->failed(new RuntimeException('timeout PostGIS'));

    $run = UgcTrackMatchingRun::find($ugc);
    expect($run->status)->toBe(UgcTrackMatchingRun::STATUS_FAILED);
    expect($run->error)->toContain('timeout PostGIS');
});

it('il job per App accoda solo le tracce da calcolare', function () {
    Bus::fake([MatchUgcTrackToEcTracksJob::class]);
    $todo = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');
    $done = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');
    UgcTrackMatchingService::make()->matchUgcTrack($done);

    (new RematchAppUgcTracksJob($this->app_->id))->handle(UgcTrackMatchingService::make());

    Bus::assertDispatched(MatchUgcTrackToEcTracksJob::class, fn ($j) => $j->ugcTrackId === $todo);
    Bus::assertNotDispatched(MatchUgcTrackToEcTracksJob::class, fn ($j) => $j->ugcTrackId === $done);
});

it('il failed() di un job non sovrascrive un calcolo riuscito dopo che il job è stato accodato', function () {
    // Caso reale della traccia 297: job accodato, nel frattempo un calcolo sincrono riesce
    // (11:05), poi l'ultimo tentativo del job va in timeout e failed() arriva alle 11:13.
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');
    $this->travelTo('2026-10-13 11:00:00');
    $job = new MatchUgcTrackToEcTracksJob($ugc);
    $this->travelTo('2026-10-13 11:05:00');
    UgcTrackMatchingService::make()->matchUgcTrack($ugc);
    $this->travelTo('2026-10-13 11:13:00');

    $job->failed(new RuntimeException('timeout'));

    $run = UgcTrackMatchingRun::find($ugc);
    expect($run->status)->toBe(UgcTrackMatchingRun::STATUS_COMPUTED);
    expect($run->computed_at->format('H:i'))->toBe('11:05');
});

it('il failed() di un job scrive failed sopra un calcolo riuscito prima che il job fosse accodato', function () {
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');
    $this->travelTo('2026-10-13 10:00:00');
    UgcTrackMatchingService::make()->matchUgcTrack($ugc);
    $this->travelTo('2026-10-13 11:00:00');
    $job = new MatchUgcTrackToEcTracksJob($ugc);
    $this->travelTo('2026-10-13 11:13:00');

    $job->failed(new RuntimeException('timeout'));

    $run = UgcTrackMatchingRun::find($ugc);
    expect($run->status)->toBe(UgcTrackMatchingRun::STATUS_FAILED);
    expect($run->error)->toBe('timeout');
});

it('il failed() di un job scrive failed sopra un calcolo partito prima dell accodamento e finito dopo', function () {
    // Il calcolo parte alle 10:58; mentre gira (durante l'INSERT delle righe) il job viene
    // accodato alle 11:00, e il calcolo si chiude alle 11:05. computed_at è l'inizio, 10:58:
    // non è un calcolo partito dopo il job, quindi il failed() delle 11:13 lo sovrascrive.
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11 44, 11 44.001))');
    $job = null;
    DB::listen(function ($query) use (&$job, $ugc) {
        if ($job === null && str_contains($query->sql, 'INSERT INTO ugc_track_ec_track_matches')) {
            $this->travelTo('2026-10-13 11:00:00');
            $job = new MatchUgcTrackToEcTracksJob($ugc);
            $this->travelTo('2026-10-13 11:05:00');
        }
    });
    $this->travelTo('2026-10-13 10:58:00');
    UgcTrackMatchingService::make()->matchUgcTrack($ugc);
    expect($job)->not->toBeNull();
    expect(UgcTrackMatchingRun::find($ugc)->computed_at->format('H:i'))->toBe('10:58');
    $this->travelTo('2026-10-13 11:13:00');

    $job->failed(new RuntimeException('timeout'));

    expect(UgcTrackMatchingRun::find($ugc)->status)->toBe(UgcTrackMatchingRun::STATUS_FAILED);
});

it('il momento di accodamento sopravvive alla serializzazione del job', function () {
    $this->travelTo('2026-10-13 11:00:00');
    $job = unserialize(serialize(new MatchUgcTrackToEcTracksJob(42)));

    expect($job->queuedAt->format('Y-m-d H:i:s'))->toBe('2026-10-13 11:00:00');
});
