<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Wm\WmPackage\Models\App;
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
    // ci sono e-mail e nomi delle persone indicate come soggetti (oc:8960).
    $application = TrailApplication::factory()->create();
    // La geometria passa da SQL puro, come ovunque nel package: senza,
    // getGeojson() torna null e il test non proverebbe niente.
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $application->id]
    );
    $application->writeApiData([
        'surveying_subjects' => [['subject' => 'person']],
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

    expect($mancanti['fields'])->toContain('to', 'distance', 'managing_subjects')
        ->and($mancanti['fields'])->not->toContain('from')
        ->and($mancanti['attachments'])->toContain('report', 'maintenance_plan')
        ->and($mancanti['attachments'])->not->toContain('authorizations');
});

it('non considera obbligatori i campi facoltativi', function () {
    $facoltativi = ['intermediate_stop', 'ascent', 'overlapping_routes', 'keeping_subjects', 'themes'];

    // Uno per uno, non `not->toContain(...$facoltativi)`: quella forma nega
    // «li contiene tutti», quindi passerebbe anche se uno solo mancasse.
    expect(array_intersect($facoltativi, TrailApplication::requiredApiFields()))->toBe([]);
});

it('non pretende la difficolta proposta per la conferma', function () {
    // Il campo 37 il format lo valida nella forma — «mappa attivita' ->
    // livello, si valida la coppia» — ma non ne chiede la presenza, a
    // differenza di quelli che dicono «obbligatorio». Pretenderlo
    // rifiuterebbe un'istanza che il format considera completa, e il cittadino
    // non chiuderebbe la pratica; la difficolta' che finisce sul sentiero
    // pubblicato la assegna comunque il Catasto in istruttoria (oc:8960).
    expect(TrailApplication::requiredApiFields())->not->toContain('difficulty');
});

it('non considera obbligatoria la e-mail di un soggetto, che dipende dalla sua natura', function () {
    // Dopo oc:9039 l'unica obbligatorieta' condizionata e' quella della
    // e-mail dentro una lista di soggetti: la governa la validazione della
    // chiamata, non l'elenco dei campi dell'istanza. Un'istanza con un solo
    // custode persona fisica, senza e-mail, deve potersi confermare.
    expect(TrailApplication::requiredApiFields())
        ->not->toContain('keeping_subjects');

    $application = TrailApplication::factory()->create();
    $application->writeApiData([
        'managing_subjects' => [['subject' => 'person']],
        'surveying_subjects' => [['subject' => 'person']],
        'maintaining_subjects' => [['subject' => 'person']],
    ]);

    // Gli altri campi obbligatori restano mancanti: qui conta che nessuno dei
    // tre ruoli lo sia, pur senza una sola e-mail nelle tre liste.
    expect($application->missingForConfirmation()['fields'])
        ->not->toContain('managing_subjects')
        ->not->toContain('surveying_subjects')
        ->not->toContain('maintaining_subjects');
});

it('considera incompleta un istanza a cui manca un ruolo obbligatorio', function () {
    $application = TrailApplication::factory()->create();
    $application->writeApiData([
        'managing_subjects' => [],
        'surveying_subjects' => [['subject' => 'person']],
    ]);

    expect($application->missingForConfirmation()['fields'])
        ->toContain('managing_subjects', 'maintaining_subjects')
        ->not->toContain('surveying_subjects')
        ->not->toContain('keeping_subjects');
});

it('la factory completa produce liste di soggetti che lo schema accetta', function () {
    // `complete()` crea anche gli allegati: servono l'app di riferimento dei
    // media e il disco finto, come fanno i test sugli allegati.
    Bus::fake();
    config(['wm-package.shard_name' => 'wm_package_testing']);
    App::factory()->createQuietly();
    Storage::fake(config('media-library.disk_name', 'wmfe'));
    Storage::fake('wmfe');

    $application = TrailApplication::factory()->complete()->create();

    $validator = Validator::make(
        (array) $application->api_data,
        app(\Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema::class)->rules()
    );

    // Gli altri campi restano segnaposto (`'valore'`): quello che questo test
    // garantisce e' che nessuna delle quattro liste di soggetti sia malformata,
    // perche' una lista non vuota con dentro oggetti sbagliati supererebbe
    // `missingForConfirmation()` senza che nessuno se ne accorga.
    $erroriSuiSoggetti = array_values(array_filter(
        $validator->errors()->keys(),
        fn (string $key) => str_contains($key, '_subjects'),
    ));

    expect($erroriSuiSoggetti)->toBe([]);
});
