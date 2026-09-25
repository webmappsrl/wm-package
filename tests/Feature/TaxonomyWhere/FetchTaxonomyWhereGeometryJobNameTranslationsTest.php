<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\TaxonomyWhere\FetchTaxonomyWhereGeometryJob;
use Wm\WmPackage\Models\TaxonomyWhere;

uses(TestCase::class, DatabaseTransactions::class);

/**
 * Richiesta del dev durante il test manuale di oc:8588: il dettaglio OSMFeatures deve alimentare
 * le 5 traduzioni delle lingue della piattaforma (it/en/de/fr/es), non solo 'it', e sostituisce
 * sempre il nome della where con queste 5 lingue (il dettaglio e' la fonte piu' completa), anche
 * quando la where aveva gia' un nome "affidabile".
 */
beforeEach(function () {
    config()->set('wm-package.clients.osmfeatures.host', 'https://osmfeatures.test');
});

function fakeAdminAreaDetail(string $osmfeaturesId, ?string $name, array $osmTags): void
{
    $properties = array_filter(['name' => $name, 'admin_level' => 4], fn ($v) => $v !== null);
    $properties['osm_tags'] = $osmTags;

    Http::fake([
        "*/admin-areas/{$osmfeaturesId}" => Http::response([
            'type' => 'Feature',
            'properties' => $properties,
            'geometry' => null,
        ]),
    ]);
}

it('keeps exactly the 5 platform languages for a region with all translations plus extra languages', function () {
    fakeAdminAreaDetail('R40784', 'Lazio', [
        'name' => 'Lazio',
        'name:it' => 'Lazio',
        'name:en' => 'Lazio',
        'name:de' => 'Latium',
        'name:es' => 'Lacio',
        'name:fr' => 'Latium',
        'name:ko' => '라치오',
        'name:ja' => 'ラツィオ州',
    ]);

    $where = TaxonomyWhere::create([
        'name' => [],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R40784'],
    ]);

    (new FetchTaxonomyWhereGeometryJob($where->id))->handle(app(OsmfeaturesClient::class));

    $translations = $where->fresh()->getTranslations('name');
    expect($translations)->toEqual([
        'it' => 'Lazio',
        'en' => 'Lazio',
        'de' => 'Latium',
        'es' => 'Lacio',
        'fr' => 'Latium',
    ]);
    expect($translations)->not->toHaveKey('ko');
    expect($translations)->not->toHaveKey('ja');
});

it('fills all 5 languages with the base name for a comune that only has the osm_tags name tag', function () {
    fakeAdminAreaDetail('R41900', 'Poggio Mirteto', [
        'name' => 'Poggio Mirteto',
    ]);

    $where = TaxonomyWhere::create([
        'name' => [],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R41900'],
    ]);

    (new FetchTaxonomyWhereGeometryJob($where->id))->handle(app(OsmfeaturesClient::class));

    expect($where->fresh()->getTranslations('name'))->toEqual([
        'it' => 'Poggio Mirteto',
        'en' => 'Poggio Mirteto',
        'de' => 'Poggio Mirteto',
        'fr' => 'Poggio Mirteto',
        'es' => 'Poggio Mirteto',
    ]);
});

it('uses the base name for it when name:it is missing, and it for the other missing languages', function () {
    fakeAdminAreaDetail('R99', 'Nome Base', [
        'name' => 'Nome Base',
        'name:de' => 'Name Basis',
    ]);

    $where = TaxonomyWhere::create([
        'name' => [],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R99'],
    ]);

    (new FetchTaxonomyWhereGeometryJob($where->id))->handle(app(OsmfeaturesClient::class));

    expect($where->fresh()->getTranslations('name'))->toEqual([
        'it' => 'Nome Base',
        'de' => 'Name Basis',
        'en' => 'Nome Base',
        'fr' => 'Nome Base',
        'es' => 'Nome Base',
    ]);
});

it('uses the single available translation for all 5 languages when only a non-italian one exists and there is no base name', function () {
    fakeAdminAreaDetail('R77', null, [
        'name:fr' => 'Seul Nom',
    ]);

    $where = TaxonomyWhere::create([
        'name' => [],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R77'],
    ]);

    (new FetchTaxonomyWhereGeometryJob($where->id))->handle(app(OsmfeaturesClient::class));

    expect($where->fresh()->getTranslations('name'))->toEqual([
        'fr' => 'Seul Nom',
        'it' => 'Seul Nom',
        'en' => 'Seul Nom',
        'de' => 'Seul Nom',
        'es' => 'Seul Nom',
    ]);
});

it('replaces an osm id placeholder stored on a platform language', function () {
    fakeAdminAreaDetail('R1', 'Nome Vero', []);

    $where = TaxonomyWhere::create([
        'name' => ['en' => 'R1', 'it' => 'R1'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R1'],
    ]);

    (new FetchTaxonomyWhereGeometryJob($where->id))->handle(app(OsmfeaturesClient::class));

    $translations = $where->fresh()->getTranslations('name');
    expect($translations['it'])->toBe('Nome Vero');
    expect($translations['en'])->toBe('Nome Vero');
});

it('leaves the name untouched when the detail has no name at all', function () {
    fakeAdminAreaDetail('R2', null, []);

    $where = TaxonomyWhere::create([
        'name' => ['en' => 'Nome Precedente'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R2'],
    ]);

    (new FetchTaxonomyWhereGeometryJob($where->id))->handle(app(OsmfeaturesClient::class));

    $translations = $where->fresh()->getTranslations('name');
    expect($translations)->toEqual(['en' => 'Nome Precedente']);
    foreach ($translations as $value) {
        expect($value)->not->toBe('R2');
    }
});

it('keeps an existing translation outside the 5 platform languages', function () {
    fakeAdminAreaDetail('R3', 'Nome Vero', [
        'name' => 'Nome Vero',
    ]);

    $where = TaxonomyWhere::create([
        'name' => ['sc' => 'Nomen Sardu'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R3'],
    ]);

    (new FetchTaxonomyWhereGeometryJob($where->id))->handle(app(OsmfeaturesClient::class));

    $translations = $where->fresh()->getTranslations('name');
    expect($translations['sc'])->toBe('Nomen Sardu');
    expect($translations['it'])->toBe('Nome Vero');
});

/**
 * Fix di review (oc:8588): RetryTaxonomyWhereGeometryFetch::dispatchGeometryJob() dispatcha
 * questo job anche quando source !== 'osmfeatures', se getOsmfeaturesId() non e' vuoto — es. una
 * where GeoHub che porta ancora un osmfeatures_id "residuo" perche' HasTaxonomyWhereImportHelpers
 * ::executeGeohubImport() trova il record esistente per identifier/geohub_id e fa
 * array_merge($existing->properties ?? [], $properties) senza rimuovere una eventuale chiave
 * osmfeatures_id gia' presente. Il nome di un record del genere e' curato da GeoHub e non deve
 * essere sovrascritto da syncNameFromDetail() — la geometria invece continua ad aggiornarsi per
 * ogni sorgente, qui non tocchiamo quella parte.
 */
it('does not touch the name of a non-osmfeatures where with a stale osmfeatures_id, but still updates the geometry', function () {
    Http::fake([
        '*/admin-areas/R5' => Http::response([
            'type' => 'Feature',
            'properties' => ['name' => 'Nome OSM Diverso', 'admin_level' => 8],
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[[8.0, 41.0], [9.0, 41.0], [9.0, 42.0], [8.0, 42.0], [8.0, 41.0]]],
            ],
        ]),
    ]);

    $where = TaxonomyWhere::create([
        'name' => ['it' => 'Nome GeoHub'],
        // osmfeatures_id "residuo" su una where con source geohub: scenario reale documentato
        // sopra, non un caso di laboratorio.
        'properties' => ['source' => 'geohub', 'geohub_id' => 999, 'osmfeatures_id' => 'R5'],
    ]);

    (new FetchTaxonomyWhereGeometryJob($where->id))->handle(app(OsmfeaturesClient::class));

    $fresh = $where->fresh();
    expect($fresh->getTranslations('name'))->toEqual(['it' => 'Nome GeoHub']);

    $geometry = DB::selectOne(
        'SELECT ST_AsGeoJSON(geometry) as geojson FROM taxonomy_wheres WHERE id = ?',
        [$where->id]
    );
    expect($geometry->geojson)->not->toBeNull();
});

it('syncs names for a legacy osmfeatures where with no source in properties at all (pre oc:8469 record)', function () {
    fakeAdminAreaDetail('R6', 'Nome Vero', ['name' => 'Nome Vero']);

    // Bypass volontario di Eloquent/TaxonomyWhere::booted() (che stampa sempre 'source' su ogni
    // creating()): simula un record legacy inserito prima che l'import osmfeatures scrivesse
    // esplicitamente 'source' (prima di b6ddbda6, oc:8469) — la migration di backfill
    // dell'identifier di quel fix usa COALESCE(properties->>'source', ''), prova diretta che
    // esistono/esistevano righe cosi' in produzione.
    $whereId = DB::table('taxonomy_wheres')->insertGetId([
        'name' => json_encode([]),
        'properties' => json_encode(['osmfeatures_id' => 'R6']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new FetchTaxonomyWhereGeometryJob($whereId))->handle(app(OsmfeaturesClient::class));

    expect(TaxonomyWhere::find($whereId)->getTranslations('name'))->toEqual([
        'it' => 'Nome Vero',
        'en' => 'Nome Vero',
        'de' => 'Nome Vero',
        'fr' => 'Nome Vero',
        'es' => 'Nome Vero',
    ]);
});

/**
 * Nota minore emersa in review (oc:8588): quando mancano sia 'it' sia il nome base, e sono
 * presenti piu' traduzioni non italiane, il fallback usato per le lingue ancora senza nome e' la
 * PRIMA risolta nell'ordine di FetchTaxonomyWhereGeometryJob::PLATFORM_LANGUAGES (it, en, de, fr,
 * es) — qui 'de' precede 'fr', quindi 'de' vince come fallback per it/en/es. Il caso di 2+
 * traduzioni non italiane non era coperto da nessun altro test: lo fissiamo qui.
 */
it('uses the first resolved translation in PLATFORM_LANGUAGES order as fallback, when it and the base name are both missing', function () {
    fakeAdminAreaDetail('R8', null, [
        'name:de' => 'Deutscher Name',
        'name:fr' => 'Nom Francais',
    ]);

    $where = TaxonomyWhere::create([
        'name' => [],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R8'],
    ]);

    (new FetchTaxonomyWhereGeometryJob($where->id))->handle(app(OsmfeaturesClient::class));

    expect($where->fresh()->getTranslations('name'))->toEqual([
        'de' => 'Deutscher Name',
        'fr' => 'Nom Francais',
        'it' => 'Deutscher Name',
        'en' => 'Deutscher Name',
        'es' => 'Deutscher Name',
    ]);
});
