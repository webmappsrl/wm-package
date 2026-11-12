<?php

use Illuminate\Bus\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Wm\WmPackage\Http\Controllers\Api\TrailRegistry\StoreApplicationController;
use Wm\WmPackage\Http\Requests\TrailRegistry\StoreApplicationRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Tests\Feature\TrailRegistry\Support\ShardTrailApplication;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorExhaustedException;
use Wm\WmPackage\TrailRegistry\Jobs\UpdateTrailApplicationDemJob;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;
use Wm\WmPackage\TrailRegistry\Services\ApplicationDemService;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * L'endpoint di creazione della domanda (oc:8968).
 *
 * Le rotte vivono nello shard e nel package non esistono: qui se ne registra
 * una al volo, con un nome neutro, che punta al controller.
 */
beforeEach(function () {
    runTrailRegistryStubs();
    config(['wm-package.features.trail_registry.enabled' => true]);

    // Stesso setup degli altri test sugli allegati: l'observer dei media ripiega
    // sull'app 1, i job accodati dal modello non devono partire davvero e il
    // disco dei media e' finto.
    Bus::fake();
    DB::statement('ALTER SEQUENCE apps_id_seq RESTART WITH 1');
    config(['wm-package.shard_name' => 'wm_package_testing']);
    App::factory()->createQuietly();
    Storage::fake(config('media-library.disk_name', 'wmfe'));
    Storage::fake('wmfe');
    Storage::fake('public');

    // Le famiglie di difficolta' servono alla regola di validazione del modulo.
    foreach (['hiking-difficulty' => ['T', 'E', 'EE', 'EEA'], 'mtb-difficulty' => ['TC', 'MC', 'BC']] as $identifier => $levels) {
        $family = TaxonomyTerm::create(['identifier' => $identifier, 'name' => ['it' => $identifier]]);
        foreach ($levels as $level) {
            TaxonomyTerm::create([
                'identifier' => 'test:'.$identifier.'-'.$level,
                'parent_id' => $family->id,
                'name' => ['it' => $level],
            ]);
        }
    }

    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    Route::post('/test-trail-registry/applications', StoreApplicationController::class)
        ->middleware('auth:api')
        ->name('trail-registry-test.applications.store');

    // Il guard `api` e' JWT e senza un secret non si costruisce nemmeno.
    config(['jwt.secret' => 'segreto-di-prova-per-il-guard-api']);
    $this->actingAs(User::factory()->create(), 'api');
});

/**
 * Un tracciato GPX di due punti con quota, dentro il settore di prova.
 */
function storeApplicationGpx(float $lon = 1, float $lat = 1): UploadedFile
{
    $endLon = $lon + 1;
    $endLat = $lat + 1;
    $gpx = <<<GPX
    <?xml version="1.0"?>
    <gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
      <trk><trkseg>
        <trkpt lat="{$lat}" lon="{$lon}"><ele>10</ele></trkpt>
        <trkpt lat="{$endLat}" lon="{$endLon}"><ele>20</ele></trkpt>
      </trkseg></trk>
    </gpx>
    GPX;

    return UploadedFile::fake()->createWithContent('traccia.gpx', $gpx);
}

/**
 * Il corpo di una chiamata `multipart/form-data` completa: i campi strutturati
 * viaggiano come stringhe JSON, come farebbe un client vero.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function storeApplicationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sentiero di prova',
        'geometry' => storeApplicationGpx(),
        'from' => 'Punto A',
        'to' => 'Punto B',
        'distance' => 12.5,
        'ascent' => 300,
        'duration_forward' => 240,
        'duration_backward' => 200,
        'activities' => json_encode(['hiking', 'mtb']),
        'difficulty' => json_encode(['hiking' => 'EE']),
        'existing_signage' => 'good_condition',
        'proposed_managing_municipalities' => json_encode(['Cagliari']),
        'municipality_email' => 'comune@example.com',
        'soggetto_gestore' => 'forestas',
        'soggetto_rilevatore' => 'applicant',
        'soggetto_manutentore' => 'forestas',
        'soggetto_custode_presente' => 'false',
        'last_survey_date' => '2026-01-15',
        'maintenance_frequency_months' => 12,
    ], $overrides);
}

function storeApplication(array $overrides = []): TestResponse
{
    return test()->post(
        route('trail-registry-test.applications.store'),
        storeApplicationPayload($overrides),
        ['Accept' => 'application/json'],
    );
}

function fakeDemResponding(): void
{
    Http::fake(['*' => Http::response([
        'type' => 'Feature',
        'properties' => [
            'distance' => 157.2, 'ascent' => 300, 'descent' => 290,
            'duration_forward_hiking' => 120, 'duration_backward_hiking' => 110,
        ],
        'geometry' => ['type' => 'MultiLineString', 'coordinates' => [[[1, 1, 100], [2, 2, 200]]]],
    ])]);
}

/**
 * Il servizio DEM che non risponde. Restituisce un contatore delle chiamate,
 * perche' "processing" deve venire dal servizio davvero chiamato e non da un
 * calcolo che non e' mai partito.
 */
function fakeDemTimingOut(): ArrayObject
{
    $calls = new ArrayObject;

    Http::fake(['*' => function () use ($calls) {
        $calls[] = 1;

        throw new ConnectionException('cURL error 28: timeout');
    }]);

    return $calls;
}

function demLockHeld(int $id): bool
{
    return Cache::store('redis')->has("dem-lock:trail_applications:{$id}");
}

it('crea l istanza in compilazione e risponde con id e dati tecnici', function () {
    fakeDemResponding();

    $response = storeApplication()
        ->assertCreated()
        ->assertJsonPath('dem_status', 'computed')
        ->assertJsonStructure(['id', 'dem_data' => ['distance', 'ascent'], 'dem_status']);

    $application = TrailApplication::find($response->json('id'));
    expect($application->status)->toBe(TrailApplicationStatus::UnderCompilation);
    expect($application->source)->toBe('api');
    expect($application->user_id)->toBe(auth('api')->id());
    expect($application->name)->toBe('Sentiero di prova');
    expect($application->getMedia('original_geometry'))->toHaveCount(1);
    expect($application->getFirstMedia('original_geometry')->file_name)->toBe('traccia.gpx');
});

it('scrive i dati tecnici e la geometria con la quota del servizio', function () {
    fakeDemResponding();

    $id = storeApplication()->assertCreated()->json('id');

    expect(TrailApplication::find($id)->properties['dem_data'])->toHaveKeys(['distance', 'ascent']);
    $wkt = DB::selectOne('SELECT ST_AsText(geometry) AS wkt FROM trail_applications WHERE id = ?', [$id])->wkt;
    expect($wkt)->toBe('MULTILINESTRING Z ((1 1 100,2 2 200))');
});

it('non restituisce il numero del sentiero, benche sia gia riservato', function () {
    fakeDemResponding();

    $response = storeApplication()->assertCreated();

    $application = TrailApplication::find($response->json('id'));
    expect($application->activeCode)->not->toBeNull();
    expect($response->json())->not->toHaveKey('code');
    // Nemmeno sotto un altro nome: il numero non compare in nessun valore.
    expect($response->getContent())->not->toContain($application->activeCode->code);
    expect($response->getContent())->not->toContain('ZNUB5');
});

it('conserva il payload intero piu la versione dello schema', function () {
    fakeDemResponding();

    $id = storeApplication(['campo_nuovo_del_format' => 'x'])->assertCreated()->json('id');

    $apiData = TrailApplication::find($id)->api_data;
    expect($apiData)->toHaveKey(ApplicationSchema::VERSION_KEY, app(ApplicationSchema::class)->version());
    expect($apiData)->toHaveKey('campo_nuovo_del_format', 'x');
    expect($apiData)->toHaveKey('from', 'Punto A');
    expect($apiData['activities'])->toBe(['hiking', 'mtb']);
    expect($apiData)->not->toHaveKeys(['name', 'geometry']);
});

it('la versione dello schema la stabilisce chi accetta, non chi consegna', function () {
    fakeDemResponding();

    $id = storeApplication(['_schema_version' => '999'])->assertCreated()->json('id');

    expect(TrailApplication::find($id)->api_data['_schema_version'])->toBe(app(ApplicationSchema::class)->version());
});

it('dichiara il calcolo in elaborazione quando il servizio non risponde, senza fallire', function () {
    $calls = fakeDemTimingOut();

    $response = storeApplication()
        ->assertCreated()
        ->assertJsonPath('dem_status', 'processing')
        ->assertJsonPath('dem_data', null);

    $application = TrailApplication::find($response->json('id'));
    // Il servizio e' stato davvero chiamato: "processing" non arriva da un
    // calcolo mai partito.
    expect($calls)->toHaveCount(1);
    expect($application->properties)->not->toHaveKey('dem_data');
    expect($application->activeCode)->not->toBeNull();
    expect($application->getMedia('original_geometry'))->toHaveCount(1);
    // La geometria dichiarata e' stata scritta anche senza il calcolo.
    $wkt = DB::selectOne('SELECT ST_AsText(geometry) AS wkt FROM trail_applications WHERE id = ?', [$application->id])->wkt;
    expect($wkt)->toBe('MULTILINESTRING Z ((1 1 10,2 2 20))');
});

it('toglie il lock del ricalcolo quando il calcolo immediato non e riuscito', function () {
    fakeDemTimingOut();

    $id = storeApplication()->assertCreated()->json('id');

    // Il modello lo prende alla creazione: se restasse, per un'ora nessun
    // percorso riaccoderebbe il calcolo.
    expect(demLockHeld($id))->toBeFalse();
});

it('lascia il lock del ricalcolo quando il calcolo immediato e riuscito', function () {
    fakeDemResponding();

    $id = storeApplication()->assertCreated()->json('id');

    expect(demLockHeld($id))->toBeTrue();
});

it('non scrive i valori dichiarati fra i valori manuali', function () {
    fakeDemResponding();

    $id = storeApplication([
        'distance' => 12.4,
        'duration_forward' => 180,
    ])->assertCreated()->json('id');

    // `classifyField()` restituisce MANUAL appena `manual_data` non e' vuoto:
    // il dichiarato vincerebbe sul calcolato e finirebbe pubblicato.
    expect(TrailApplication::find($id)->properties['manual_data'] ?? [])->toBeEmpty();
    expect(TrailApplication::find($id)->api_data)->toHaveKey('distance');
});

// Un tracciato fuori da ogni settore deve fallire subito e non lasciare niente.
it('rifiuta un tracciato fuori da ogni settore senza lasciare righe', function () {
    fakeDemResponding();
    $before = TrailApplication::count();

    storeApplication(['geometry' => storeApplicationGpx(lon: 100, lat: 50)])
        ->assertStatus(422)
        ->assertJsonStructure(['error'])
        ->assertJsonPath('error', 'The track does not fall in any sector of the registry');

    expect(TrailApplication::count())->toBe($before);
    expect(DB::table('trail_registry_codes')->count())->toBe(0);
    expect(DB::table('media')->count())->toBe(0);
    Http::assertNothingSent();
});

it('traduce un settore esaurito in una risposta comprensibile, senza il codice del settore', function () {
    fakeDemResponding();

    $this->mock(TrailRegistryService::class)
        ->shouldReceive('reserve')
        ->once()
        ->andThrow(SectorExhaustedException::forFullCode('ZNUB5'));

    $response = storeApplication()
        ->assertStatus(422)
        ->assertJsonPath('error', 'The sector the track falls in has no free numbers left');

    expect($response->getContent())->not->toContain('ZNUB5');
    expect(TrailApplication::count())->toBe(0);
});

// Quattro motivi per cui un file non si legge, quattro risposte distinte: chi
// integra deve capire cosa correggere senza scriverci.
dataset('unreadable geometry files', [
    'ne gpx ne geojson' => ['non e un tracciato', 'The file is neither a GPX nor a GeoJSON: upload the track in one of the two formats'],
    'gpx che non e xml valido' => ['<gpx><trk><trkseg>', 'The GPX file is not valid XML'],
    'geojson che non e json valido' => ['{"type": "LineString", ', 'The GeoJSON file is not valid JSON'],
    'gpx con un segmento di un solo punto' => [
        '<gpx version="1.1"><trk><trkseg><trkpt lat="1" lon="1"><ele>1</ele></trkpt></trkseg></trk></gpx>',
        'The file contains no line with at least two points: a file with only points of interest, or a segment of a single point, does not describe a trail',
    ],
    'geojson di soli punti di interesse' => [
        '{"type": "Point", "coordinates": [1, 1]}',
        'The file contains no line with at least two points: a file with only points of interest, or a segment of a single point, does not describe a trail',
    ],
]);

it('dice perche il file del tracciato non si legge, senza lasciare righe', function (string $content, string $key) {
    $response = storeApplication(['geometry' => UploadedFile::fake()->createWithContent('x.gpx', $content)])
        ->assertStatus(422)
        ->assertJsonPath('error', __($key));

    expect($response->json('error'))->not->toBeEmpty();
    expect(TrailApplication::count())->toBe(0);
})->with('unreadable geometry files');

it('usa un messaggio diverso per ciascuno dei quattro motivi', function () {
    $errors = collect(['non e un tracciato', '<gpx><trk><trkseg>', '{"type": "LineString", ', '{"type": "Point", "coordinates": [1, 1]}'])
        ->map(fn ($content) => storeApplication(['geometry' => UploadedFile::fake()->createWithContent('x.gpx', $content)])->json('error'));

    expect($errors->unique())->toHaveCount(4);
});

it('rifiuta un modulo non valido dicendo il campo, senza lasciare righe', function () {
    storeApplication(['municipality_email' => 'non-una-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['municipality_email']);

    expect(TrailApplication::count())->toBe(0);
});

it('non lascia righe se un passo della creazione fallisce a meta', function () {
    fakeDemResponding();

    // La riga e la geometria sono gia' scritte quando la riserva solleva.
    $this->mock(TrailRegistryService::class)
        ->shouldReceive('reserve')
        ->once()
        ->andThrow(new RuntimeException('guasto improvviso'));

    storeApplication()->assertStatus(500);

    expect(TrailApplication::count())->toBe(0);
    expect(DB::table('media')->count())->toBe(0);
});

it('accoda il calcolo quando risponde processing: la promessa dipende dal job', function () {
    fakeDemTimingOut();

    $id = storeApplication()->assertCreated()->assertJsonPath('dem_status', 'processing')->json('id');

    Bus::assertDispatched(UpdateTrailApplicationDemJob::class, fn ($job) => $job->applicationId === $id);
});

it('non accoda il calcolo se la creazione fallisce', function () {
    // Bus::fake() registra il dispatch anche quando e' `afterCommit` e la
    // transazione poi va in rollback: non prova niente. Qui il dispatcher e'
    // quello vero, con la coda sincrona: un job che parte lo si vede
    // dall'evento di inizio elaborazione.
    Bus::clearResolvedInstances();
    app()->instance(Illuminate\Contracts\Bus\Dispatcher::class, app(Dispatcher::class));
    config(['queue.default' => 'sync']);

    $this->mock(ApplicationDemService::class)->shouldNotReceive('computeAndStore');

    $started = [];
    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$started) {
        $started[] = $event->job->resolveName();
    });

    storeApplication(['geometry' => storeApplicationGpx(lon: 100, lat: 50)])->assertStatus(422);

    expect($started)->not->toContain(UpdateTrailApplicationDemJob::class);
    expect(TrailApplication::count())->toBe(0);
});

it('non lascia il file su disco se la creazione fallisce dopo averlo salvato', function () {
    // Il guasto arriva dopo `addMedia`: la riga del media va in rollback, il
    // file su disco no. Il calcolo e' l'ultimo passo della transazione.
    $this->mock(ApplicationDemService::class)
        ->shouldReceive('computeAndStore')
        ->once()
        ->andThrow(new RuntimeException('guasto dopo il salvataggio del file'));

    storeApplication()->assertStatus(500);

    expect(TrailApplication::count())->toBe(0);
    expect(DB::table('media')->count())->toBe(0);
    expect(Storage::disk(config('media-library.disk_name', 'wmfe'))->allFiles())->toBe([]);
});

it('non toglie il file di una domanda committata se un callback dopo il commit solleva', function () {
    // `DB::transaction()` esegue i callback `afterCommit` dopo il COMMIT vero e
    // fuori dal blocco protetto: la stessa sequenza dell'accodamento del job
    // quando la coda non risponde. La domanda esiste, il file non va toccato.
    $this->mock(ApplicationDemService::class)
        ->shouldReceive('computeAndStore')
        ->once()
        ->andReturnUsing(function () {
            DB::afterCommit(fn () => throw new RuntimeException('coda non raggiungibile'));

            return false;
        });

    storeApplication()->assertStatus(500);

    expect(TrailApplication::count())->toBe(1);
    $media = TrailApplication::first()->getFirstMedia('original_geometry');
    expect($media)->not->toBeNull();
    expect(Storage::disk($media->disk)->exists($media->getPathRelativeToRoot()))->toBeTrue();
});

it('conserva il file quando la creazione riesce, perche il test sopra non sia vero per costruzione', function () {
    fakeDemResponding();

    storeApplication()->assertCreated();

    expect(Storage::disk(config('media-library.disk_name', 'wmfe'))->allFiles())->not->toBeEmpty();
});

it('non fallisce se il rilascio del lock non riesce: un retry creerebbe una seconda domanda', function () {
    fakeDemTimingOut();
    Log::spy();

    $real = Cache::getFacadeRoot();
    $broken = Mockery::mock($real->store('redis'))->makePartial();
    $broken->shouldReceive('forget')->andThrow(new RuntimeException('Redis irraggiungibile'));
    Cache::partialMock()->shouldReceive('store')->andReturnUsing(
        fn ($name = null) => $name === 'redis' ? $broken : $real->store($name)
    );

    $response = storeApplication()->assertCreated()->assertJsonPath('dem_status', 'processing');

    expect(TrailApplication::find($response->json('id')))->not->toBeNull();
    expect(TrailApplication::count())->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(
        fn ($message) => str_contains($message, 'lock del ricalcolo DEM non rilasciato')
    )->once();
});

it('crea la domanda nella classe che lo shard ha configurato', function () {
    // Su uno shard che configura una propria sottoclasse, la domanda nata dal
    // canale deve essere di quella: e' su di lei che gira il suo `booted()`, ed
    // e' sotto il suo nome che Nova cerca gli allegati.
    config(['wm-package.features.trail_registry.models.application' => ShardTrailApplication::class]);
    ShardTrailApplication::$created = 0;
    fakeDemResponding();

    $id = storeApplication()->assertCreated()->json('id');

    expect(ShardTrailApplication::$created)->toBe(1)
        ->and(DB::table('media')->where('model_id', $id)->where('collection_name', 'original_geometry')->value('model_type'))
        ->toBe(ShardTrailApplication::class);
});

it('rifiuta una traccia oltre i 5 MB del format, prima di leggerla', function () {
    // Il limite e' quello che il format fissa per ogni file del fascicolo,
    // traccia compresa. Il rifiuto arriva dalla validazione: il file non viene
    // letto ne' analizzato.
    fakeDemResponding();

    storeApplication([
        'geometry' => UploadedFile::fake()->create('troppo-grande.gpx', StoreApplicationRequest::MAX_GEOMETRY_KILOBYTES + 1, 'application/gpx+xml'),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['geometry']);

    expect(TrailApplication::count())->toBe(0);
});

it('accetta una traccia di esattamente 5 MB', function () {
    fakeDemResponding();

    // Un file fake di quella misura non e' un GPX vero: il controllo e' che non
    // sia la dimensione a respingerlo, e infatti l'errore, se c'e', non e' su
    // `max`.
    $response = storeApplication([
        'geometry' => UploadedFile::fake()->create('al-limite.gpx', StoreApplicationRequest::MAX_GEOMETRY_KILOBYTES, 'application/gpx+xml'),
    ]);

    expect(json_encode($response->json('errors.geometry') ?? []))->not->toContain('5120');
});
