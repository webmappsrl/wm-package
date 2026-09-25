<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Nova\Fields\ActionFields;
use Tests\TestCase;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\TaxonomyWhere\FetchTaxonomyWhereGeometryJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Nova\Actions\ImportTaxonomyWhere;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\TaxonomyWhereDisplayService;

uses(TestCase::class, DatabaseTransactions::class);

/**
 * Regressione trovata in review su oc:8588: un nome vuoto ('name' => [] su un campo Spatie
 * HasTranslations) viene salvato come testo letterale '[]' (json_encode([]), non '{}'), e
 * taxonomyWhereAggregateSql() (GeometryComputationService) lo trattava come un nome reale
 * letterale "[]" invece che come "nessun nome" — riproducibile end-to-end quando un'area OSM
 * non ha ne' un nome affidabile nell'elenco (import) ne' nel dettaglio (job).
 */
it('never turns a name missing from both list and detail into the osm id or the literal "[]" in outputs', function () {
    config()->set('wm-package.clients.osmfeatures.host', 'https://osmfeatures.test');
    Bus::fake();

    $app = App::factory()->create(['map_bbox' => json_encode([-0.5, -0.5, 0.5, 0.5])]);

    $client = Mockery::mock(OsmfeaturesClient::class);
    $client->shouldReceive('getAdminAreasIds')
        ->once()
        ->andReturn([
            // getAdminAreasIds() risolve gia' a null quando non c'e' ne' 'it' ne' 'en'.
            ['id' => 'R_EMPTY_NAME', 'name' => null, 'updated_at' => now()->toIso8601String()],
        ]);
    app()->instance(OsmfeaturesClient::class, $client);

    $action = new ImportTaxonomyWhere;
    $fields = new ActionFields(collect(['source_type' => 'osmfeatures_8', 'app_id' => $app->id]), collect());
    $action->handle($fields, collect());

    $where = TaxonomyWhere::whereRaw("properties->>'osmfeatures_id' = ?", ['R_EMPTY_NAME'])->first();
    expect($where)->not->toBeNull();
    expect($where->getTranslations('name'))->toBe([]);
    // Verifica diretta della colonna grezza: Spatie salva un array vuoto come '[]' letterale,
    // non '{}' — il punto esatto del bug segnalato in review.
    expect(DB::table('taxonomy_wheres')->where('id', $where->id)->value('name'))->toBe('[]');

    // Geometria nota (bbox intorno all'origine, fuori da qualunque copertura reale del DB —
    // stesso punto "Golfo di Guinea" usato altrove in GeometryComputationServiceTaxonomyWhereTest).
    DB::statement(
        'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
        ['{"type":"Polygon","coordinates":[[[-0.5,-0.5],[0.5,-0.5],[0.5,0.5],[-0.5,0.5],[-0.5,-0.5]]]}', $where->id]
    );

    // Il job di dettaglio non trova un nome nemmeno li': OsmfeaturesClient::getAdminAreaDetail()
    // (oc:8588) restituisce 'name' => null e 'names' => []
    // quando 'properties.name'/'osm_tags' non hanno nulla (non ripiega piu' sull'id) — la
    // guardia di syncNameFromDetail() (nessuna traduzione risolta -> return) scarta il caso
    // prima di scrivere qualunque cosa, stesso esito di prima ma per una ragione diversa.
    Http::fake([
        '*/admin-areas/R_EMPTY_NAME' => Http::response([
            'type' => 'Feature',
            'properties' => ['admin_level' => 8],
            'geometry' => null,
        ]),
    ]);
    app()->forgetInstance(OsmfeaturesClient::class);
    (new FetchTaxonomyWhereGeometryJob($where->id))->handle(app(OsmfeaturesClient::class));

    $fresh = $where->fresh();
    expect($fresh->getTranslations('name'))->toBe([]);
    expect(DB::table('taxonomy_wheres')->where('id', $where->id)->value('name'))->toBe('[]');

    // Uscita aggregata (stessa query SQL usata dal sync bulk e dal resync conservativo):
    // l'entry non deve contenere ne' 'it'/'en' ne' tantomeno il testo letterale "[]" o l'id OSM.
    $poiId = DB::table('ec_pois')->insertGetId([
        'name' => json_encode(['it' => 'Poi senza where con nome']),
        'app_id' => $app->id,
        'user_id' => $app->user_id,
        'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[0.0,0.0,0]}')"),
        'properties' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $computed = GeometryComputationService::make()->computeTaxonomyWhere(EcPoi::find($poiId));

    // taxonomyWhereAggregateSql() esclude dall'aggregato le where senza nome valido (oc:8588,
    // review): una entry senza nome non compare affatto, ne' come "[]" ne' come l'id OSM.
    expect($computed)->not->toHaveKey('R_EMPTY_NAME');
    expect($computed)->toBe([]);

    // La normalizzazione per la visualizzazione pubblica (TaxonomyWhereDisplayService) deve
    // scartare del tutto un'entry senza nome, non mostrarla come "[]" ne' come l'id OSM.
    $normalized = app(TaxonomyWhereDisplayService::class)->normalize($computed);
    expect($normalized)->toBe([]);
});
