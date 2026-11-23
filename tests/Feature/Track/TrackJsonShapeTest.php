<?php

declare(strict_types=1);

require_once __DIR__.'/../../Concerns/InsertsGeometryFixtures.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Enums\WmfeTrackVersion;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\Tests\Concerns\InsertsGeometryFixtures;

/**
 * oc:8997 — la forma del JSON della traccia, congelata **per ogni versione**.
 *
 * Il file senza versione, `<shard>/tracks/{id}.json`, è l'indirizzo compilato dentro le app già
 * pubblicate sugli store: la sua forma non può cambiare, ed è il motivo per cui questo test è
 * nato. Ma ogni versione è un contratto verso qualcuno, quindi ognuna ha la sua fixture: un
 * refactor che cambiasse la forma della `v1` romperebbe chi la sta adottando, e passerebbe
 * inosservato se a essere congelata fosse la sola versione vecchia.
 *
 * È un approval test: confronta la struttura del file prodotto con quella in
 * `tests/fixtures/track/<versione>-shape.json`, quindi al primo giro di una versione nuova passa.
 * Il suo valore è nei refactor successivi. A fixture assente, `WM_CAPTURE_SHAPE=1` la scrive
 * invece di fallire — ed è l'unico modo per crearne una: non si riscrive una fixture esistente
 * per far passare un test rosso.
 *
 * Confronta la **forma**, non i valori: i valori seguono il dato (R1 dell'overview). La
 * distinzione fra lista JSON e oggetto JSON è però parte della forma, ed è il punto delicato:
 * `JsonResource::filter()` reindicizza gli array con chiavi tutte numeriche, quindi
 * `taxonomy_where` esce come lista o come oggetto a seconda degli id delle zone. Di qui i due
 * casi: chiavi tutte numeriche e chiavi miste, queste ultime come nel file di produzione.
 *
 * **Quando nasce una versione nuova** non c'è niente da scrivere qui: il test cicla su
 * `WmfeTrackVersion::cases()`. Si lancia una volta con `WM_CAPTURE_SHAPE=1` per catturare le sue
 * due fixture, e da lì in poi è congelata.
 */
uses(DatabaseTransactions::class, InsertsGeometryFixtures::class);

/**
 * La fixture di una versione: `legacy-shape.json`, `v1-shape.json`, e le varianti con le zone a
 * chiavi miste.
 */
function shapeFixturePath(WmfeTrackVersion $version, string $case): string
{
    return __DIR__.'/../../fixtures/track/'.$version->value.'-shape'.($case === '' ? '' : '-'.$case).'.json';
}

beforeEach(function () {
    config(['wm-package.shard_name' => 'test-shard']);
    Storage::fake('wmfe');
});

/**
 * La firma di una struttura JSON: le chiavi e i tipi, senza i valori.
 *
 * Lavora sul JSON decodificato **a oggetti**, non ad array associativi: è l'unico modo per
 * distinguere `{}` da `[]`, che in PHP diventerebbero lo stesso array vuoto, e per non scambiare
 * per lista un oggetto con chiavi `"0"`, `"1"`, `"2"`.
 *
 * Un oggetto diventa una mappa con le chiavi ordinate, così l'ordine di inserimento non conta —
 * il che vuol dire che questo test non vede un cambio di ordine delle chiavi, che per un parser
 * JSON non è una differenza. Di una lista si ispeziona il primo elemento.
 */
function shapeOf(mixed $value): mixed
{
    if ($value instanceof stdClass) {
        $properties = get_object_vars($value);

        if ($properties === []) {
            return '{}empty';
        }

        $shape = [];
        foreach ($properties as $key => $item) {
            $shape[(string) $key] = shapeOf($item);
        }
        ksort($shape);

        return $shape;
    }

    if (is_array($value)) {
        return $value === [] ? '[]empty' : ['[]' => shapeOf($value[0])];
    }

    return get_debug_type($value);
}

/**
 * Una traccia con tutto ciò che la forma del file deve contenere: le quattro forme delle
 * tassonomie, i dati DEM e manuali, due media e tre POI correlati di cui uno scartato.
 */
function trackWithEveryShape(): EcTrack
{
    $app = App::factory()->createQuietly();

    // Non esistono factory per le tassonomie: si creano a mano, con identifier unico dentro
    // DatabaseTransactions (un identifier fisso fa scattare il controllo doppioni dell'observer).
    $where = TaxonomyWhere::create(['name' => ['it' => 'Zona '.uniqid()]]);
    $activity = TaxonomyActivity::create(['name' => ['it' => 'Attività '.uniqid()]]);

    $trackId = test()->insertEcTrackWithGeometry($app, [
        'ref' => 'SS-1',
        'from' => 'Inizio',
        'to' => 'Fine',
        'cai_scale' => 'E',
        // Le chiavi che l'overview elenca come lette dal frontend e da mantenere: senza, un
        // refactor che ne perdesse una passerebbe verde.
        'description' => ['it' => 'Descrizione'],
        'excerpt' => ['it' => 'Estratto'],
        'difficulty' => ['it' => 'Media'],
        'color' => '#ff0000',
        'osm_url' => 'https://www.openstreetmap.org/relation/1',
        'related_url' => ['Sito' => 'https://example.test'],
        'audio' => ['it' => 'https://example.test/a.mp3'],
        'config_detail' => [['layout' => 'title', 'attributes' => ['title' => 'Scheda']]],
        'not_accessible' => true,
        'not_accessible_message' => ['it' => 'Frana in corso'],
        'layers' => [1],
        'duration_forward' => 120,
        'duration_backward' => 110,
        // Due forme, e il file reale le produce entrambe:
        // - chiavi tutte numeriche → `filter()` di JsonResource reindicizza e la chiave esce come
        //   lista JSON;
        // - una chiave non numerica in mezzo (gli id osmfeatures, `R40158`) → resta oggetto.
        // Sul file di produzione della 769 vale il secondo caso.
        // Le chiavi sono fisse, non l'id della zona appena creata: un id che cambia a ogni run
        // finirebbe dentro la fixture del caso misto, dove le chiavi restano, e la renderebbe
        // instabile. Nel caso tutto-numerico non si vedrebbe, perché `filter()` reindicizza.
        'taxonomy_where' => ['81' => ['name' => ['it' => 'Zona'], 'admin_level' => 8]],
        'manual_data' => ['distance' => 12.5],
        'dem_data' => [
            'distance' => 12.1, 'ascent' => 300, 'descent' => 280,
            'ele_from' => 100, 'ele_to' => 900, 'ele_min' => 90, 'ele_max' => 950,
            'duration_forward' => 130, 'duration_backward' => 120,
        ],
    ]);

    $track = EcTrack::find($trackId);
    $track->taxonomyWheres()->attach($where->id);
    $track->taxonomyActivities()->attach($activity->id);

    // Tre POI correlati. Il caso del POI scartato da getRelatedPois() non è riproducibile qui:
    // `ec_pois.osmfeatures_id` non esiste nel database di test del package (è una colonna di
    // osm2cai2). La reindicizzazione di JsonResource resta coperta da `taxonomy_where`, che ha
    // chiavi tutte numeriche.
    for ($i = 0; $i < 3; $i++) {
        $track->ecPois()->attach(test()->insertEcPoiWithGeometry($app, ['name' => ['it' => 'Poi']]));
    }

    $track->addMedia(UploadedFile::fake()->image('uno.jpg', 800, 600))->toMediaCollection('default');
    $track->addMedia(UploadedFile::fake()->image('due.jpg', 800, 600))->toMediaCollection('default');

    return $track->fresh();
}

/**
 * Una traccia costruita con le properties di un sentiero vero, prese dal database di forestas e
 * versionate in `tests/fixtures/track/production-track-input.json`: `taxonomy_where` con le sue
 * chiavi miste (`5`, `R39853`), i Blocchi Dettaglio con l'HTML intero, `manual_data`, `via`,
 * `layers`, le attività con i loro identifier.
 *
 * Non sono dati verosimili inventati né accorciati: sono quelli che il file su `wmfe` ha davvero.
 */
function productionTrack(): EcTrack
{
    $input = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/track/production-track-input.json'), true);

    $app = App::factory()->createQuietly();
    $track = EcTrack::find(test()->insertEcTrackWithGeometry($app, $input['properties']));

    foreach ($input['activities'] as $identifier) {
        $activity = TaxonomyActivity::firstOrCreate(
            ['identifier' => $identifier],
            ['name' => ['it' => $identifier]]
        );
        $track->taxonomyActivities()->attach($activity->id);
    }

    $track->addMedia(UploadedFile::fake()->image('uno.jpg', 800, 600))->toMediaCollection('default');
    $track->addMedia(UploadedFile::fake()->image('due.jpg', 800, 600))->toMediaCollection('default');

    return $track->fresh();
}

/**
 * Una traccia senza niente: nessuna zona, nessuna attività, nessun media, nessun POI correlato,
 * nessun dato DEM o manuale. Serve a congelare i casi vuoti, che sono quelli in cui una chiave
 * sparisce o compare dal nulla senza che nessuno se ne accorga.
 */
function emptyTrack(): EcTrack
{
    return EcTrack::find(test()->insertEcTrackWithGeometry(App::factory()->createQuietly(), []))->fresh();
}

/**
 * Il JSON scritto, per versione, decodificato ad array: serve al congelamento dei valori.
 *
 * @return array<string, array<string, mixed>>
 */
function writtenValues(EcTrack $track): array
{
    config(['wm-package.wmfe_track_versions' => implode(',', array_column(WmfeTrackVersion::cases(), 'value'))]);

    (new UpdateEcTrackAwsJob($track))->handle(new StorageService);

    $files = [];
    foreach (WmfeTrackVersion::cases() as $version) {
        $path = '/test-shard/tracks/'.$version->pathPrefix()."{$track->id}.json";
        $files[$version->value] = json_decode((string) Storage::disk('wmfe')->get($path), true);
    }

    return $files;
}

function writtenShapes(EcTrack $track): array
{
    // Tutte le versioni conosciute attive insieme: il test congela ogni contratto, non solo
    // quelli che uno shard sta scrivendo oggi.
    config(['wm-package.wmfe_track_versions' => implode(',', array_column(WmfeTrackVersion::cases(), 'value'))]);

    (new UpdateEcTrackAwsJob($track))->handle(new StorageService);

    $shapes = [];
    foreach (WmfeTrackVersion::cases() as $version) {
        $path = '/test-shard/tracks/'.$version->pathPrefix()."{$track->id}.json";
        $contents = Storage::disk('wmfe')->get($path);

        expect($contents)->not->toBeNull("Manca il file $path");

        $shapes[$version->value] = (array) shapeOf(json_decode((string) $contents));
    }

    return $shapes;
}

function assertShapeMatchesFixture(array $shape, string $fixture): void
{
    if (getenv('WM_CAPTURE_SHAPE') && ! file_exists($fixture)) {
        file_put_contents($fixture, json_encode($shape, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    expect(file_exists($fixture))->toBeTrue(
        "La fixture $fixture non esiste: rilancia con WM_CAPTURE_SHAPE=1 per catturarla."
    );

    expect($shape)->toEqual(json_decode((string) file_get_contents($fixture), true));
}

it('congela la forma di ogni versione del file', function (string $case) {
    $track = match ($case) {
        'production' => productionTrack(),
        'empty' => emptyTrack(),
        default => trackWithEveryShape(),
    };

    $shapes = writtenShapes($track);

    foreach (WmfeTrackVersion::cases() as $version) {
        assertShapeMatchesFixture($shapes[$version->value], shapeFixturePath($version, $case));
    }
})->with([
    'un sentiero vero, preso dalla produzione' => 'production',
    'zone con id tutti numerici, che escono come lista JSON' => '',
    'traccia senza zone, attività, media né POI' => 'empty',
]);

it('fra una versione e l\'altra cambia solo la forma delle tassonomie', function () {
    // Il contratto di R2, letto dalle fixture congelate invece che dal codice che le produce.
    $shapes = writtenShapes(productionTrack());

    $legacy = $shapes[WmfeTrackVersion::Legacy->value]['properties'];
    $current = $shapes[WmfeTrackVersion::current()->value]['properties'];

    expect(array_keys(array_diff_key($legacy, $current)))
        ->toEqualCanonicalizing(['taxonomy', 'taxonomy_where', 'taxonomyWheres'])
        ->and(array_diff_key($current, $legacy))->toBe([])
        ->and($current['taxonomies'])->toEqual($legacy['taxonomies']);
});

/**
 * Sostituisce i valori che cambiano a ogni esecuzione — gli id autoincrement, le date, gli id
 * dentro i percorsi dei media — con un segnaposto.
 *
 * È l'unico motivo per cui i valori non si potrebbero congelare: tutto il resto del file dipende
 * dai dati che il test scrive, e quelli sono fissi. La sostituzione è **mirata**, mai un
 * `str_replace` dell'id su tutta la stringa: l'id `4` colpirebbe anche il `400` di
 * `uno-thumbnail_400_200.jpg`.
 */
function withoutVolatileValues(mixed $value, int $trackId): mixed
{
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = match (true) {
                in_array($key, ['id', 'created_at', 'updated_at'], true) => '<volatile>',
                is_string($key) && str_ends_with($key, '_id') => '<volatile>',
                default => withoutVolatileValues($item, $trackId),
            };
        }

        return $normalized;
    }

    if (! is_string($value)) {
        return $value;
    }

    // Il percorso dei media e' `/<shard>/<app-id>/media/<media-id>/`: quel primo numero e' l'id
    // dell'app, non della traccia. Senza questa riga il test passava solo quando le due sequenze
    // coincidevano, e falliva a seconda dell'ordine in cui la suite gira.
    $value = preg_replace('#/media/#', '/media/', (string) preg_replace(
        '#(/'.preg_quote(config('wm-package.shard_name'), '#').'/)\d+(/media/)#',
        '$1<app-id>$2',
        $value
    ));

    // Percorsi e URL: gli id di media, POI e traccia sono segmenti di path.
    $value = preg_replace('#/media/\d+/#', '/media/<media-id>/', $value);
    $value = preg_replace('#/api/media/\d+#', '/api/media/<media-id>', $value);
    $value = preg_replace('#/api/ec/poi/download/\d+#', '/api/ec/poi/download/<poi-id>', $value);
    $value = preg_replace('#/api/ec/track/download/\d+#', '/api/ec/track/download/<track-id>', $value);
    $value = preg_replace('#/'.preg_quote((string) $trackId, '#').'/#', '/<track-id>/', $value);

    return preg_replace('#(/ec-track/|/track/|=)'.preg_quote((string) $trackId, '#').'\b#', '$1<track-id>', $value);
}

it('congela i valori di ogni versione, non solo la forma', function () {
    // I dati di input sono quelli di un sentiero vero e non cambiano: tolti gli id e le date,
    // tutto il resto del file è deterministico. Così il congelamento prende anche ciò che la
    // firma delle chiavi non vede — il filtro delle località mostrate, `roundtrip`, la priorità
    // MANUAL > OSM > DEM, i testi che passano invariati.
    $track = productionTrack();
    $files = writtenValues($track);

    foreach (WmfeTrackVersion::cases() as $version) {
        assertShapeMatchesFixture(
            (array) withoutVolatileValues($files[$version->value], $track->id),
            __DIR__.'/../../fixtures/track/'.$version->value.'-values.json'
        );
    }
});
