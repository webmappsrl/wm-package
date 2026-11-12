<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Http\Clients\DemClient;
use Wm\WmPackage\Jobs\Track\UpdateEcTrack3DDemJob;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\TrailRegistry\Jobs\UpdateTrailApplicationDemJob;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Services\ApplicationDemService;

beforeEach(function () {
    runTrailRegistryStubs();

    $this->application = TrailApplication::factory()->create();
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $this->application->id]
    );
});

function demOk(): array
{
    return [
        'type' => 'Feature',
        'properties' => [
            'distance' => 157.2, 'ascent' => 300, 'descent' => 290,
            'duration_forward_hiking' => 120, 'duration_backward_hiking' => 110,
        ],
        'geometry' => ['type' => 'MultiLineString', 'coordinates' => [[[1, 1, 100], [2, 2, 200]]]],
    ];
}

it('scrive i dati tecnici e la geometria tridimensionale', function () {
    Http::fake(['*' => Http::response(demOk())]);

    expect(app(ApplicationDemService::class)->computeAndStore($this->application->fresh()))->toBeTrue();

    $this->application->refresh();
    expect($this->application->properties['dem_data'])->toHaveKeys(['distance', 'ascent', 'duration_forward']);
    $wkt = DB::selectOne('SELECT ST_AsText(geometry) AS wkt FROM trail_applications WHERE id = ?', [$this->application->id])->wkt;
    expect($wkt)->toBe('MULTILINESTRING Z ((1 1 100,2 2 200))');
});

it('non solleva e restituisce false quando il servizio non risponde', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: timeout')]);
    Log::spy();

    expect(app(ApplicationDemService::class)->computeAndStore($this->application->fresh(), 3))->toBeFalse();

    Log::shouldHaveReceived('warning')->once();
    expect($this->application->fresh()->properties)->not->toHaveKey('dem_data');
});

it('un errore SQL nella lettura della geometria non lascia abortita la transazione esterna', function () {
    Http::fake(['*' => Http::response(demOk())]);
    Log::spy();

    // La lettura della geometria e' la prima query del calcolo: se fallisse
    // fuori dal savepoint, Postgres resterebbe abortito (25P02) e ogni query
    // successiva, commit compreso, fallirebbe.
    $broken = Mockery::mock(TrailApplication::class)->makePartial();
    $broken->forceFill(['id' => $this->application->id]);
    $broken->shouldReceive('getGeojson')->andReturnUsing(
        fn () => DB::select('SELECT * FROM tabella_che_non_esiste')
    );

    DB::transaction(function () use ($broken) {
        expect(app(ApplicationDemService::class)->computeAndStore($broken))->toBeFalse();

        expect(DB::selectOne('SELECT 1 AS ok')->ok)->toBe(1);
    });

    Log::shouldHaveReceived('warning')->once();
});

it('non lascia uscire il corpo della risposta del servizio, lo logga', function () {
    $body = '<html>errore interno del servizio</html>';
    Http::fake(['*' => Http::response($body, 500)]);
    Log::spy();

    expect(app(ApplicationDemService::class)->computeAndStore($this->application->fresh()))->toBeFalse();

    Log::shouldHaveReceived('warning')->withArgs(
        fn ($message, $context = []) => ($context['body'] ?? null) === $body
    )->once();
    Log::shouldHaveReceived('warning')->withArgs(
        fn ($message, $context = []) => str_contains($context['message'] ?? '', 'HTTP 500')
            && ! str_contains($context['message'], $body)
    )->once();
});

it('il messaggio dell eccezione del client non contiene body né nome di job', function () {
    Http::fake(['*' => Http::response('<html>segreto</html>', 502)]);

    try {
        app(DemClient::class)->getTechData(['type' => 'Feature']);
        $this->fail('attesa eccezione');
    } catch (Exception $e) {
        expect($e->getMessage())->not->toContain('segreto')
            ->and($e->getMessage())->not->toContain('Job')
            ->and($e->getMessage())->toContain('502');
    }
});

it('il client applica il timeout senza retry: 5 secondi di default, o quello passato', function () {
    $options = [];
    Http::fake(function (Request $request, array $opts) use (&$options) {
        $options[] = $opts;

        return Http::response(demOk());
    });

    app(DemClient::class)->getTechData(['type' => 'Feature']);
    app(DemClient::class)->getTechData(['type' => 'Feature'], 2);

    expect($options)->toHaveCount(2)
        ->and($options[0]['timeout'])->toBe(5)
        ->and($options[1]['timeout'])->toBe(2)
        ->and($options[0]['connect_timeout'])->toBe(5);
});

it('il percorso in coda non eredita il limite sincrono', function () {
    Http::fake(['*' => Http::response(demOk())]);
    $client = Mockery::mock(DemClient::class);
    $client->shouldReceive('getTechData')->once()->withArgs(fn ($g, $t) => $t === 30)->andReturn(demOk());
    app()->instance(DemClient::class, $client);

    (new UpdateTrailApplicationDemJob($this->application->id))->handle(app(ApplicationDemService::class));
});

it('la chiamata sincrona usa il limite basso configurato', function () {
    config(['wm-package.clients.dem.timeout_seconds' => 4]);
    $client = Mockery::mock(DemClient::class);
    $client->shouldReceive('getTechData')->once()->withArgs(fn ($g, $t) => $t === 4)->andReturn(demOk());
    app()->instance(DemClient::class, $client);

    expect(app(ApplicationDemService::class)->computeAndStore($this->application->fresh()))->toBeTrue();
});

it('una scrittura che fallisce restituisce false anche dentro una transazione aperta', function () {
    $bad = demOk();
    $bad['geometry'] = ['type' => 'MultiLineString', 'coordinates' => 'malformata'];
    Http::fake(['*' => Http::response($bad)]);
    Log::spy();
    $application = $this->application->fresh();

    DB::transaction(function () use ($application) {
        expect(app(ApplicationDemService::class)->computeAndStore($application))->toBeFalse();

        // Senza savepoint la transazione sarebbe abortita e questa query fallirebbe.
        expect(DB::selectOne('SELECT 1 AS ok')->ok)->toBe(1);
        DB::update('UPDATE trail_applications SET properties = ?::jsonb WHERE id = ?', ['{"x":1}', $application->id]);
    });

    expect($this->application->fresh()->properties['x'])->toBe(1);
    Log::shouldHaveReceived('warning')->once();
});

it('una risposta 200 con properties malformate restituisce false', function () {
    $bad = demOk();
    $bad['properties'] = 'non un array';
    Http::fake(['*' => Http::response($bad)]);

    expect(app(ApplicationDemService::class)->computeAndStore($this->application->fresh()))->toBeFalse();
});

it('restituisce false se l istanza sparisce prima della scrittura', function () {
    Http::fake(['*' => Http::response(demOk())]);
    $application = $this->application->fresh();
    DB::statement('DELETE FROM trail_applications WHERE id = ?', [$application->id]);

    expect(app(ApplicationDemService::class)->computeAndStore($application))->toBeFalse();
});

it('il client non accetta un timeout zero', function () {
    $options = [];
    Http::fake(function (Request $request, array $opts) use (&$options) {
        $options[] = $opts;

        return Http::response(demOk());
    });
    config(['wm-package.clients.dem.timeout_seconds' => 0]);

    app(DemClient::class)->getTechData(['type' => 'Feature']);

    expect($options[0]['timeout'])->toBe(1);
});

it('UpdateEcTrack3DDemJob usa il limite lungo e non quello corto', function () {
    $options = [];
    Http::fake(function (Request $request, array $opts) use (&$options) {
        $options[] = $opts;

        return Http::response(demOk());
    });
    config(['wm-package.clients.dem.timeout_seconds' => 5, 'wm-package.clients.dem.async_timeout_seconds' => 30]);
    $track = Mockery::mock(EcTrack::class)->makePartial();
    $track->shouldReceive('getGeojson')->andReturn(['type' => 'Feature']);
    $track->shouldReceive('saveQuietly');
    $geometry = Mockery::mock(GeometryComputationService::class);
    $geometry->shouldReceive('getWktFromGeojson')->andReturn('MULTILINESTRING Z ((1 1 0, 2 2 0))');

    (new UpdateEcTrack3DDemJob($track))->handle(app(DemClient::class), $geometry);

    expect($options[0]['timeout'])->toBe(30);
});
