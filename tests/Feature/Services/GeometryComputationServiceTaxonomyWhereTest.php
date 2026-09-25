<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\GeometryComputationService;

uses(TestCase::class, DatabaseTransactions::class);

/**
 * Crea una TaxonomyWhere con geometria poligonale nota (bbox Corsica), identifier
 * randomizzato per non collidere con dati QA reali del DB di sviluppo condiviso
 * (stesso pattern di SyncTaxonomyWhereJobTest.php).
 */
function createCorsicaTaxonomyWhere(): TaxonomyWhere
{
    $taxonomyWhere = new TaxonomyWhere([
        'name' => 'Corsica',
        'properties' => ['source' => 'geohub', 'admin_level' => 4],
    ]);
    $taxonomyWhere->identifier = 'corsica-'.Str::lower(Str::random(8));
    $taxonomyWhere->save();

    DB::statement(
        'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
        ['{"type":"Polygon","coordinates":[[[8.53,41.33],[9.56,41.33],[9.56,43.03],[8.53,43.03],[8.53,41.33]]]}', $taxonomyWhere->id]
    );

    return $taxonomyWhere->fresh();
}

it('syncs taxonomy_where in bulk for EcTrack', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $trackId = DB::table('ec_tracks')->insertGetId([
        'name' => json_encode(['it' => 'Track in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"MultiLineString\",\"coordinates\":[[[9.0,42.0,0],[9.1,42.1,0]]]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $synced = GeometryComputationService::make()->syncTaxonomyWhere(EcTrack::class);

    expect($synced)->toBeGreaterThanOrEqual(1);
    $track = EcTrack::find($trackId);
    expect($track->properties['taxonomy_where'] ?? [])->not->toBeEmpty();
});

it('syncs taxonomy_where in bulk for EcPoi', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05,0]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $synced = GeometryComputationService::make()->syncTaxonomyWhere(EcPoi::class);

    expect($synced)->toBeGreaterThanOrEqual(1);
    $poi = EcPoi::find($poiId);
    expect($poi->properties['taxonomy_where'] ?? [])->not->toBeEmpty();
});

it('scopes the sync to a single EcPoi id without touching other rows', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $inCoverageId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05,0]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $otherInCoverageId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Altro Poi in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.06,42.06,0]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $synced = GeometryComputationService::make()->syncTaxonomyWhere(EcPoi::class, $inCoverageId);

    expect($synced)->toBe(1);
    expect(EcPoi::find($inCoverageId)->properties['taxonomy_where'] ?? [])->not->toBeEmpty();
    expect(EcPoi::find($otherInCoverageId)->properties['taxonomy_where'] ?? [])->toBeEmpty();
});

it('scopes the sync to a single EcTrack id without touching other rows', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $inCoverageId = DB::table('ec_tracks')->insertGetId([
        'name' => json_encode(['it' => 'Track in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"MultiLineString\",\"coordinates\":[[[9.0,42.0,0],[9.1,42.1,0]]]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $otherInCoverageId = DB::table('ec_tracks')->insertGetId([
        'name' => json_encode(['it' => 'Altra Track in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"MultiLineString\",\"coordinates\":[[[9.2,42.2,0],[9.3,42.3,0]]]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $synced = GeometryComputationService::make()->syncTaxonomyWhere(EcTrack::class, $inCoverageId);

    expect($synced)->toBe(1);
    expect(EcTrack::find($inCoverageId)->properties['taxonomy_where'] ?? [])->not->toBeEmpty();
    expect(EcTrack::find($otherInCoverageId)->properties['taxonomy_where'] ?? [])->toBeEmpty();
});

it('writes the legacy taxonomy_where shape with language keys, _admin_level and _source', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05,0]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    GeometryComputationService::make()->syncTaxonomyWhere(EcPoi::class, $poiId);

    // createCorsicaTaxonomyWhere() assegna 'name' a un modello con Spatie HasTranslations
    // (Taxonomy::$translatable): una stringa semplice viene scritta solo per la locale
    // corrente (APP_LOCALE=en in .env/.env.testing di questo progetto), quindi in colonna
    // il JSON contiene solo 'en', non 'it' (verificato: raw column = {"en":"Corsica"}).
    $entry = collect(EcPoi::find($poiId)->properties['taxonomy_where'])->first();
    expect($entry)->toEqual(['en' => 'Corsica', '_admin_level' => 4, '_source' => 'geohub']);
});

it('computes the taxonomy_where of a record without writing it', function () {
    createCorsicaTaxonomyWhere();
    $app = App::factory()->create();

    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi in Corsica']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[9.05,42.05,0]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $computed = GeometryComputationService::make()->computeTaxonomyWhere(EcPoi::find($poiId));

    // Stessa nota della prova precedente: il record Corsica ha solo la traduzione 'en'.
    expect(collect($computed)->first())->toEqual(['en' => 'Corsica', '_admin_level' => 4, '_source' => 'geohub']);
    expect(EcPoi::find($poiId)->properties['taxonomy_where'] ?? null)->toBeNull();
});

it('preserves an existing taxonomy_where when no local coverage matches AND preserveOnNoMatch is true (bulk/resync)', function () {
    $app = App::factory()->create();

    // Golfo di Guinea: nessuna TaxonomyWhere reale del DB di sviluppo condiviso lo copre
    // (verificato: 0 righe con ST_Intersects su questo punto) — a differenza del punto
    // "Corsica" [9.05,42.05] usato altrove in questo file, che oggi interseca where reali
    // già presenti nel DB (id 1 "Corsica", id 2 "Francia"), non solo quelle create dal test.
    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi fuori copertura']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[0.0,0.0,0]}')"),
        'properties' => json_encode([
            'taxonomy_where' => [
                'R999999' => ['name' => ['it' => 'Regione Precedente'], 'admin_level' => 4, 'source' => 'osmfeatures'],
            ],
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Nessuna TaxonomyWhere locale creata in questo test: la subquery ST_Intersects non trova nulla.
    GeometryComputationService::make()->syncTaxonomyWhere(EcPoi::class, $poiId, preserveOnNoMatch: true);

    $properties = EcPoi::find($poiId)->properties;
    expect($properties['taxonomy_where'])->toHaveKey('R999999');
    expect($properties['taxonomy_where']['R999999']['name']['it'])->toBe('Regione Precedente');
});

it('clears an existing taxonomy_where when no local coverage matches AND preserveOnNoMatch is false (default, path scoped automatico)', function () {
    $app = App::factory()->create();

    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi fuori copertura']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[0.0,0.0,0]}')"),
        'properties' => json_encode([
            'taxonomy_where' => [
                'R999999' => ['name' => ['it' => 'Regione Precedente'], 'admin_level' => 4, 'source' => 'osmfeatures'],
            ],
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Nessun parametro esplicito: il default deve azzerare, non preservare.
    GeometryComputationService::make()->syncTaxonomyWhere(EcPoi::class, $poiId);

    $properties = EcPoi::find($poiId)->properties;
    expect($properties['taxonomy_where'] ?? [])->toBeEmpty();
});

/**
 * Where con nome vuoto: Spatie HasTranslations salva un array di traduzioni vuoto come testo
 * letterale '[]' (json_encode([]), non '{}'), e la geometria e' nota ma fuori da qualunque
 * copertura reale del DB (stesso punto "Golfo di Guinea" usato altrove in questo file).
 */
function createEmptyNameTaxonomyWhere(): TaxonomyWhere
{
    $taxonomyWhere = new TaxonomyWhere([
        'name' => [],
        'properties' => ['source' => 'osmfeatures', 'admin_level' => 8, 'osmfeatures_id' => 'R_EMPTY_NAME_GC'],
    ]);
    $taxonomyWhere->identifier = 'empty-name-'.Str::lower(Str::random(8));
    $taxonomyWhere->save();

    DB::statement(
        'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
        ['{"type":"Polygon","coordinates":[[[-0.5,-0.5],[0.5,-0.5],[0.5,0.5],[-0.5,0.5],[-0.5,-0.5]]]}', $taxonomyWhere->id]
    );

    return $taxonomyWhere->fresh();
}

/**
 * Where con geometria nota, nome valorizzato ('it'/'en'), stesso bbox di
 * createEmptyNameTaxonomyWhere() (Golfo di Guinea) cosi' un poi a [0,0] interseca entrambe.
 */
function createNamedOriginTaxonomyWhere(): TaxonomyWhere
{
    $taxonomyWhere = new TaxonomyWhere([
        'name' => ['it' => 'Regione con nome', 'en' => 'Named region'],
        'properties' => ['source' => 'geohub', 'admin_level' => 4],
    ]);
    $taxonomyWhere->identifier = 'named-origin-'.Str::lower(Str::random(8));
    $taxonomyWhere->save();

    DB::statement(
        'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
        ['{"type":"Polygon","coordinates":[[[-0.5,-0.5],[0.5,-0.5],[0.5,0.5],[-0.5,0.5],[-0.5,-0.5]]]}', $taxonomyWhere->id]
    );

    return $taxonomyWhere->fresh();
}

/**
 * Where con geometria nota e nome presente ma con soli valori vuoti/spazi
 * (`{"it":"","en":"  "}`): a differenza di createEmptyNameTaxonomyWhere() (colonna '[]'), qui la
 * colonna e' un JSON object valido con chiavi lingua valide, ma nessun valore non-vuoto — stesso
 * bbox "Golfo di Guinea" usato altrove in questo file. Inserita via query builder, bypassando
 * Eloquent/Spatie HasTranslations (che scarta silenziosamente una chiave con stringa vuota già in
 * scrittura — verificato in tinker: `setTranslations(['it' => '', 'en' => '  '])` produce
 * `{"en":"  "}`, non `{"it":"","en":"  "}`): il caso segnalato in review (oc:8588, fix round 1)
 * riguarda il filtro SQL letto da un dato già presente in colonna, indipendentemente da come ci
 * sia arrivato (import diretto, dato storico, o un'altra fonte che bypassa Spatie).
 */
function createBlankNameTaxonomyWhere(): TaxonomyWhere
{
    $id = DB::table('taxonomy_wheres')->insertGetId([
        'name' => '{"it":"","en":"  "}',
        'properties' => json_encode(['source' => 'osmfeatures', 'admin_level' => 8, 'osmfeatures_id' => 'R_BLANK_NAME_GC']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::statement(
        'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
        ['{"type":"Polygon","coordinates":[[[-0.5,-0.5],[0.5,-0.5],[0.5,0.5],[-0.5,0.5],[-0.5,-0.5]]]}', $id]
    );

    return TaxonomyWhere::find($id);
}

it('excludes a where whose names are all blank/whitespace from the aggregate (oc:8588, fix round 1)', function () {
    $where = createBlankNameTaxonomyWhere();
    expect(json_decode(DB::table('taxonomy_wheres')->where('id', $where->id)->value('name'), true))
        ->toBe(['it' => '', 'en' => '  ']);

    $app = App::factory()->create();
    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi vicino a where con nome vuoto']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[0.0,0.0,0]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $aloneResult = GeometryComputationService::make()->computeTaxonomyWhere(EcPoi::find($poiId));
    expect($aloneResult)->toBe([]);

    // Insieme a una where CON nome: solo quella con nome deve restare nel risultato.
    createNamedOriginTaxonomyWhere();
    $combinedResult = GeometryComputationService::make()->computeTaxonomyWhere(EcPoi::find($poiId));

    expect($combinedResult)->not->toHaveKey('R_BLANK_NAME_GC');
    expect(array_keys($combinedResult))->toHaveCount(1);
    expect(collect($combinedResult)->first())->toEqual(['it' => 'Regione con nome', 'en' => 'Named region', '_admin_level' => 4, '_source' => 'geohub']);
});

it('excludes a nameless where from the aggregate entirely, even when it is not the only one found (oc:8588)', function () {
    $where = createEmptyNameTaxonomyWhere();
    // Il punto esatto segnalato in review: la colonna grezza e' '[]', non '{}'.
    expect(DB::table('taxonomy_wheres')->where('id', $where->id)->value('name'))->toBe('[]');

    $app = App::factory()->create();
    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi vicino a where senza nome']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[0.0,0.0,0]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Il POI e' anche in copertura di una where CON nome, cosi' l'aggregato non e' vuoto e
    // possiamo verificare che l'unica voce senza nome venga scartata, non solo svuotata.
    createNamedOriginTaxonomyWhere();

    $computed = GeometryComputationService::make()->computeTaxonomyWhere(EcPoi::find($poiId));

    expect($computed)->not->toHaveKey('R_EMPTY_NAME_GC');
    expect(array_keys($computed))->toHaveCount(1);
    $entry = collect($computed)->first();
    expect($entry)->toEqual(['it' => 'Regione con nome', 'en' => 'Named region', '_admin_level' => 4, '_source' => 'geohub']);
});

it('returns an empty result when the only where in coverage has no valid name (oc:8588)', function () {
    createEmptyNameTaxonomyWhere();
    $app = App::factory()->create();

    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi solo vicino a where senza nome']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[0.0,0.0,0]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $computed = GeometryComputationService::make()->computeTaxonomyWhere(EcPoi::find($poiId));
    expect($computed)->toBe([]);

    // syncTaxonomyWhere scoped (preserveOnNoMatch di default false per una chiamata con id):
    // il conteggio finale deve essere 0, come nel caso "nessuna where in copertura".
    $synced = GeometryComputationService::make()->syncTaxonomyWhere(EcPoi::class, $poiId);
    expect($synced)->toBe(0);
    expect(EcPoi::find($poiId)->properties['taxonomy_where'] ?? [])->toBeEmpty();
});

it('defaults preserveOnNoMatch to true for a bulk call (no id) even without passing the parameter explicitly', function () {
    $app = App::factory()->create();

    // Un secondo EcPoi in copertura, per rendere la chiamata realmente bulk (nessun id passato)
    // pur avendo un solo record che ci interessa verificare.
    $poiIdOutOfCoverage = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi fuori copertura']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[0.0,0.0,0]}')"),
        'properties' => json_encode([
            'taxonomy_where' => [
                'R999999' => ['name' => ['it' => 'Regione Precedente'], 'admin_level' => 4, 'source' => 'osmfeatures'],
            ],
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Chiamata bulk (nessun $modelId) senza passare preserveOnNoMatch: un futuro chiamante bulk
    // che dimentica il parametro deve restare comunque protetto (finder 5, re-review oc:8487).
    GeometryComputationService::make()->syncTaxonomyWhere(EcPoi::class);

    $properties = EcPoi::find($poiIdOutOfCoverage)->properties;
    expect($properties['taxonomy_where'])->toHaveKey('R999999');
});
