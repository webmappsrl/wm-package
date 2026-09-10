<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Http\Requests\CreateResourceRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailGeometryException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication as TrailApplicationModel;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication as TrailApplicationResource;
use Wm\WmPackage\TrailRegistry\TrailGeometryReader;

beforeEach(function () {
    runTrailRegistryStubs();

    // Stesse ragioni di ApproveTrailApplicationTest: gli observer di
    // geometria e media chiamano servizi esterni e pretendono un'app 1.
    Bus::fake();
    DB::statement('ALTER SEQUENCE apps_id_seq RESTART WITH 1');
    config(['wm-package.shard_name' => 'wm_package_testing']);
    App::factory()->createQuietly();

    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $this->reader = app(TrailGeometryReader::class);
});

function gpxWithTrack(): string
{
    return <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
      <trk><name>Sentiero di prova</name><trkseg>
        <trkpt lat="1.0" lon="1.0"><ele>120.5</ele></trkpt>
        <trkpt lat="2.0" lon="2.0"><ele>240.0</ele></trkpt>
      </trkseg></trk>
    </gpx>
    XML;
}

function gpxWithRoute(): string
{
    return <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
      <rte><name>Rotta di prova</name>
        <rtept lat="1.0" lon="1.0"/>
        <rtept lat="2.0" lon="2.0"/>
      </rte>
    </gpx>
    XML;
}

it('legge un GPX con traccia mantenendo la quota', function () {
    expect($this->reader->wktFrom(gpxWithTrack()))
        ->toBe('MULTILINESTRING Z ((1 1 120.5, 2 2 240))');
});

it('legge un GPX che pubblica l itinerario come rotta invece che come traccia', function () {
    // Sei tracce di Sardegna Sentieri arrivavano cosi' (vedi il fix
    // import-gpx-route su forestas): senza questo ramo il file sembra vuoto.
    expect($this->reader->wktFrom(gpxWithRoute()))
        ->toBe('MULTILINESTRING Z ((1 1 0, 2 2 0))');
});

it('legge un GeoJSON Feature con una LineString', function () {
    $geojson = json_encode([
        'type' => 'Feature',
        'properties' => [],
        'geometry' => [
            'type' => 'LineString',
            'coordinates' => [[1, 1, 100], [2, 2, 200]],
        ],
    ]);

    expect($this->reader->wktFrom($geojson))
        ->toBe('MULTILINESTRING Z ((1 1 100, 2 2 200))');
});

it('legge un GeoJSON FeatureCollection con due tratti come due segmenti', function () {
    $line = fn (array $coords) => [
        'type' => 'Feature',
        'properties' => [],
        'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
    ];

    $geojson = json_encode([
        'type' => 'FeatureCollection',
        'features' => [
            $line([[1, 1], [2, 2]]),
            $line([[5, 5], [6, 6]]),
        ],
    ]);

    expect($this->reader->wktFrom($geojson))
        ->toBe('MULTILINESTRING Z ((1 1 0, 2 2 0), (5 5 0, 6 6 0))');
});

it('rifiuta un GPX di soli punti di interesse', function () {
    $gpx = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">
      <wpt lat="1.0" lon="1.0"><name>Fonte</name></wpt>
    </gpx>
    XML;

    expect(fn () => $this->reader->wktFrom($gpx))
        ->toThrow(InvalidTrailGeometryException::class);
});

it('rifiuta un segmento con un solo punto', function () {
    // Una LINESTRING di un punto e' degenere: PostGIS la rifiuta all'insert,
    // e l'errore arriverebbe all'operatore come un 500.
    $gpx = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">
      <trk><trkseg><trkpt lat="1.0" lon="1.0"/></trkseg></trk>
    </gpx>
    XML;

    expect(fn () => $this->reader->wktFrom($gpx))
        ->toThrow(InvalidTrailGeometryException::class);
});

it('rifiuta un file che non e ne XML ne JSON', function () {
    expect(fn () => $this->reader->wktFrom('questo non e un tracciato'))
        ->toThrow(InvalidTrailGeometryException::class);
});

it('rifiuta un GeoJSON con una geometria che non e una linea', function () {
    $geojson = json_encode([
        'type' => 'Feature',
        'properties' => [],
        'geometry' => ['type' => 'Point', 'coordinates' => [1, 1]],
    ]);

    expect(fn () => $this->reader->wktFrom($geojson))
        ->toThrow(InvalidTrailGeometryException::class);
});

it('permette di creare un istanza d ufficio', function () {
    expect(TrailApplicationResource::authorizedToCreate(NovaRequest::create('/')))->toBeTrue();
});

it('chiede all operatore solo denominazione e geometria', function () {
    $request = CreateResourceRequest::create('/nova-api/trail-applications', 'GET');

    $fields = collect((new TrailApplicationResource(new TrailApplicationModel))
        ->creationFields($request))
        ->map(fn ($f) => $f->name)
        ->all();

    expect($fields)->toContain('Denominazione', 'Geometria (GPX o GeoJSON)')
        // Provenienza, Stato istruttoria e Inserita da li scrive la
        // piattaforma: un'istanza nata in Nova e' per definizione d'ufficio.
        ->and($fields)->not->toContain('Provenienza', 'Stato istruttoria', 'Inserita da', 'Codice');
});

it('creando un istanza da Nova la piattaforma scrive provenienza stato e geometria', function () {
    $application = createApplicationThroughNova(gpxWithTrack(), 'Sentiero di prova');

    expect($application->source)->toBe('office')
        ->and($application->status)->toBe(TrailApplicationStatus::UnderReview)
        ->and($application->name)->toBe('Sentiero di prova')
        ->and($application->user_id)->not->toBeNull();

    $wkt = DB::selectOne(
        'SELECT ST_AsText(geometry::geometry) AS wkt FROM trail_applications WHERE id = ?',
        [$application->id]
    )->wkt;

    // ST_AsText non mette lo spazio dopo la virgola, il WKT composto dal
    // reader si': e' la stessa geometria scritta con due convenzioni.
    expect($wkt)->toBe('MULTILINESTRING Z ((1 1 120.5,2 2 240))');
});

it('creando un istanza da Nova le riserva subito un codice', function () {
    $application = createApplicationThroughNova(gpxWithTrack(), 'Sentiero di prova');

    $code = $application->activeCode;

    expect($code)->not->toBeNull()
        ->and($code->status)->toBe(TrailCodeStatus::Reserved)
        ->and($code->code)->toBe('ZNUB500');
});

it('non crea l istanza se la geometria cade fuori da ogni settore', function () {
    $gpx = str_replace(['lat="1.0"', 'lat="2.0"'], ['lat="80.0"', 'lat="81.0"'], gpxWithTrack());

    // L'operatore deve leggere il rifiuto sul campo geometria, non ricevere
    // un 500: il settore mancante e' un dato del file che ha caricato.
    expect(fn () => createApplicationThroughNova($gpx, 'Fuori settore'))
        ->toThrow(ValidationException::class);

    expect(TrailApplicationModel::count())->toBe(0);
});

/**
 * Riproduce il salvataggio di Nova: fill() dai campi del form, save(), poi
 * l'hook afterCreate — la stessa sequenza di ResourceStoreController, dentro
 * la stessa transazione, che e' cio' che rende il rifiuto un rollback.
 */
function createApplicationThroughNova(string $fileContent, string $name): TrailApplicationModel
{
    $file = UploadedFile::fake()->createWithContent('tracciato.gpx', $fileContent);

    $request = CreateResourceRequest::create(
        '/nova-api/trail-applications',
        'POST',
        ['name' => $name],
        [],
        ['geometry' => $file],
    );
    $request->setUserResolver(fn () => User::factory()->create());

    return DB::transaction(function () use ($request) {
        [$model, $callbacks] = TrailApplicationResource::fill($request, new TrailApplicationModel);

        TrailApplicationResource::beforeCreate($request, $model);

        $model->save();

        collect($callbacks)->each->__invoke();

        TrailApplicationResource::afterCreate($request, $model);

        return $model->fresh();
    });
}
