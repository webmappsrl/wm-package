<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * I dati che arrivano dall'API, nella colonna `api_data` (oc:8960). Si
 * scrivono una volta sola alla creazione, conservano anche le chiavi che non
 * sappiamo ancora mappare, non toccano ne' i valori manuali dell'operatore ne'
 * quelli calcolati dal DEM, e non escono dall'istanza.
 */
beforeEach(function () {
    runTrailRegistryStubs();
    config(['wm-package.features.trail_registry.enabled' => true]);
});

it('scrive il payload intero, anche le chiavi che non conosciamo', function () {
    $application = TrailApplication::factory()->create();

    $application->writeApiData(['from' => 'Baunei', 'campo_che_non_conosciamo' => 'valore']);

    $apiData = $application->fresh()->api_data;
    expect($apiData['from'])->toBe('Baunei')
        ->and($apiData['campo_che_non_conosciamo'])->toBe('valore');
});

it('tiene i dati api fuori dal geojson che viaggia verso il DEM', function () {
    // E' la ragione per cui api_data e' una colonna e non una chiave dentro
    // `properties`: GeoJsonService::getModelAsGeojson riversa `properties` per
    // intero nella Feature, e quella Feature parte in POST verso il servizio
    // DEM (UpdateTrailApplicationDemJob -> DemClient::getTechData). Qui dentro
    // ci sono e-mail, nomi e codici fiscali del richiedente (oc:8960).
    $application = TrailApplication::factory()->create();
    // La geometria passa da SQL puro, come ovunque nel package: senza,
    // getGeojson() torna null e il test non proverebbe niente.
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $application->id]
    );
    $application->writeApiData([
        'soggetto_rilevatore_email' => 'rilevatore@example.org',
        'soggetto_manutentore_cf' => ['RSSMRA80A01H501U'],
    ]);

    $geojson = $application->fresh()->getGeojson();

    expect($geojson)->not->toBeNull()
        ->and($geojson['properties'])->not->toHaveKey('api_data');
});

it('non tocca manual_data ne dem_data', function () {
    $application = TrailApplication::factory()->create([
        'properties' => ['manual_data' => ['distance' => 3.8], 'dem_data' => ['ascent' => 475]],
    ]);

    $application->writeApiData(['distance' => 99.9]);

    $fresh = $application->fresh();
    expect($fresh->properties['manual_data']['distance'])->toBe(3.8)
        ->and($fresh->properties['dem_data']['ascent'])->toBe(475)
        ->and($fresh->api_data['distance'])->toBe(99.9);
});

it('elenca i campi e gli allegati che mancano per la conferma', function () {
    $application = TrailApplication::factory()->create();
    $application->writeApiData(['from' => 'Baunei']);

    $mancanti = $application->fresh()->missingForConfirmation();

    expect($mancanti['fields'])->toContain('to', 'distance', 'soggetto_gestore')
        ->and($mancanti['fields'])->not->toContain('from')
        ->and($mancanti['attachments'])->toContain('report', 'maintenance_plan')
        ->and($mancanti['attachments'])->not->toContain('authorizations');
});

it('non considera obbligatori i campi facoltativi', function () {
    $facoltativi = ['intermediate_stop', 'ascent', 'overlapping_routes', 'municipality_email_alt', 'themes'];

    // Uno per uno, non `not->toContain(...$facoltativi)`: quella forma nega
    // «li contiene tutti», quindi passerebbe anche se uno solo mancasse.
    expect(array_intersect($facoltativi, TrailApplication::requiredApiFields()))->toBe([]);
});

it('non pretende la difficolta proposta per la conferma', function () {
    // Il campo 37 il format lo valida nella forma — «mappa attivita' ->
    // livello, si valida la coppia» — ma non ne chiede la presenza, a
    // differenza dei quindici che dicono «obbligatorio». Pretenderlo
    // rifiuterebbe un'istanza che il format considera completa, e il cittadino
    // non chiuderebbe la pratica; la difficolta' che finisce sul sentiero
    // pubblicato la assegna comunque il Catasto in istruttoria (oc:8960).
    expect(TrailApplication::requiredApiFields())->not->toContain('difficulty');
});

it('non considera obbligatori i campi che dipendono da un altro campo', function () {
    // L'obbligatorieta' di questi sei dipende dal valore di un altro campo: la
    // governa la validazione dell'endpoint, non l'elenco dell'istanza. Se
    // `soggetto_custode` (58) stesse fra gli obbligatori, un'istanza il cui
    // campo 56 dice che un custode non c'e' non si potrebbe confermare mai: il
    // 58 resterebbe per sempre fra i campi mancanti (oc:8960).
    $condizionati = [
        'soggetto_gestore_other',
        'soggetto_rilevatore_other',
        'soggetto_rilevatore_email',
        'soggetto_manutentore_other',
        'soggetto_manutentore_cf',
        'soggetto_custode',
    ];

    expect(array_intersect($condizionati, TrailApplication::requiredApiFields()))->toBe([]);
});
