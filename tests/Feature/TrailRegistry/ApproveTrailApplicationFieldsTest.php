<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\ActionRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ApproveTrailApplication;

beforeEach(function () {
    runTrailRegistryStubs();

    // Le dieci righe di forestas: le sette attivita' dello schema piu' tre
    // tipi di tracciato. Insert diretto, come negli altri test del dominio:
    // TaxonomyObserver scriverebbe su storage.
    foreach ([
        'hiking' => 'Alte vie', 'mtb' => 'MTB', 'trekking' => 'Trekking', 'horse' => 'Ippovia',
        'accessible-trails' => 'Accessibili', 'nordic-walking' => 'Nordic walking',
        'educational-trails' => 'Didattici', 'trail' => 'Sentiero', 'itinerary' => 'Itinerario', 'walk' => 'Passeggiata',
    ] as $identifier => $name) {
        DB::table('taxonomy_activities')->insert([
            'name' => json_encode(['it' => $name]),
            'identifier' => $identifier,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
});

/**
 * I campi dell'Action indicizzati per attributo, con il valore di partenza
 * risolto come fa Nova all'apertura del modale (resolveForAction).
 *
 * @return array<string, Field>
 */
function fieldsFor(TrailApplication $application): array
{
    // Un URI di Action vero: senza, Nova non risolve i default (vedi nova.md, oc:8543).
    $request = NovaRequest::create('/nova-api/trail-applications/actions', 'GET', ['resourceId' => $application->id]);

    return collect((new ApproveTrailApplication)->fields($request))
        ->each(fn (Field $field) => $field->resolveForAction($request))
        ->keyBy('attribute')
        ->all();
}

/** @return list<string> */
function activityOptionKeys(Field $field): array
{
    // Il Select tiene la mappa valore => etichetta nella callback delle
    // opzioni (le serializza solo in jsonSerialize); il Multiselect una lista
    // di {label, value} nel meta.
    $options = $field instanceof Select ? value($field->optionsCallback) : ($field->meta['options'] ?? []);

    return array_is_list($options) ? array_column($options, 'value') : array_keys($options);
}

it('prefilla le select con quanto il SUS ha proposto', function () {
    $application = TrailApplication::factory()->create(['api_data' => [
        'activities' => ['hiking', 'mtb'],
        'difficulty' => ['hiking' => 'E'],
        'primary_activity' => 'hiking',
    ]]);

    $fields = fieldsFor($application);

    expect($fields['activities']->value)->toBe(['hiking', 'mtb'])
        ->and($fields['primary_activity']->value)->toBe('hiking');
});

it('presenta select vuote su un\'istanza nata d\'ufficio', function () {
    $application = TrailApplication::factory()->create(['source' => 'office', 'api_data' => null]);

    $fields = fieldsFor($application);

    expect($fields['activities']->value)->toBeNull()
        ->and($fields['primary_activity']->value)->toBeNull();
});

it('non offre come attivita i tipi di tracciato', function () {
    $keys = activityOptionKeys(fieldsFor(TrailApplication::factory()->create())['activities']);

    expect($keys)->not->toContain('trail')
        ->and($keys)->not->toContain('itinerary')
        ->and($keys)->not->toContain('walk')
        ->and($keys)->toHaveCount(7)
        ->and($keys)->toContain('hiking');
});

it('etichetta le attivita con il nome della tassonomia', function () {
    $options = fieldsFor(TrailApplication::factory()->create())['activities']->meta['options'];

    $hiking = collect($options)->firstWhere('value', 'hiking');

    expect($hiking['label'])->toBe('Alte vie');
});

it('ignora una proposta che lo schema non ammette piu', function () {
    $application = TrailApplication::factory()->create(['api_data' => [
        'activities' => ['hiking', 'parapendio'],
        'primary_activity' => 'parapendio',
    ]]);

    $fields = fieldsFor($application);

    expect($fields['primary_activity']->value)->toBeNull()
        ->and($fields['activities']->value)->toBe(['hiking']);
});

it('offre come primaria solo le attivita proposte', function () {
    $application = TrailApplication::factory()->create(['api_data' => [
        'activities' => ['hiking', 'mtb'],
        'primary_activity' => 'mtb',
    ]]);

    expect(activityOptionKeys(fieldsFor($application)['primary_activity']))->toBe(['hiking', 'mtb']);
});

it('risolve l istanza anche dalla PATCH del dependsOn e ignora la selezione multipla', function () {
    $application = TrailApplication::factory()->create(['api_data' => [
        'activities' => ['mtb'], 'primary_activity' => 'mtb',
    ]]);
    $action = new ApproveTrailApplication;

    $patch = NovaRequest::create('/nova-api/trail-applications/actions', 'PATCH', ['resources' => [$application->id]]);
    $all = NovaRequest::create('/nova-api/trail-applications/actions', 'PATCH', ['resources' => 'all']);

    $fromPatch = $action->fields($patch)[1];
    $fromPatch->resolveForAction($patch);
    $fromAll = $action->fields($all)[1];
    $fromAll->resolveForAction($all);

    expect($fromPatch->value)->toBe('mtb')
        ->and($fromAll->value)->toBeNull();
});

/**
 * Semina una famiglia di difficolta' con i suoi livelli (valore => nome).
 * Insert diretto: TaxonomyObserver scriverebbe su storage.
 *
 * @param  array<string, string>  $levels
 */
function seedFamily(string $identifier, array $levels): void
{
    $parent = DB::table('taxonomy_terms')->insertGetId([
        'identifier' => $identifier, 'name' => json_encode(['it' => $identifier]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($levels as $value => $name) {
        DB::table('taxonomy_terms')->insert([
            'identifier' => $identifier.'-'.$value, 'parent_id' => $parent, 'name' => json_encode(['it' => $name]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

function seedDifficultyFamilies(): void
{
    seedFamily('hiking-difficulty', ['T' => 'T', 'E' => 'E', 'EE' => 'EE', 'EEA' => 'EEA']);
    seedFamily('mtb-difficulty', ['TC' => 'TC', 'BC' => 'BC']);
    seedFamily('inclusivity-difficulty', ['AT' => 'AT', 'AE' => 'AE']);
    seedFamily('soft-mobility-difficulty', ['easy' => 'Facile']);
}

it('offre un select per famiglia, mai la mobilita dolce', function () {
    seedDifficultyFamilies();

    $fields = fieldsFor(TrailApplication::factory()->create());

    expect($fields)->toHaveKeys(['difficulty__hiking-difficulty', 'difficulty__mtb-difficulty', 'difficulty__inclusivity-difficulty'])
        ->and($fields)->not->toHaveKey('difficulty__soft-mobility-difficulty')
        ->and(activityOptionKeys($fields['difficulty__hiking-difficulty']))->toBe(['T', 'E', 'EE', 'EEA']);
});

it('prefilla il livello di ogni famiglia traducendo l attivita', function () {
    seedDifficultyFamilies();
    $application = TrailApplication::factory()->create(['api_data' => [
        'activities' => ['accessible-trails', 'mtb'],
        'difficulty' => ['accessible-trails' => 'AE', 'mtb' => 'BC'],
        'primary_activity' => 'mtb',
    ]]);

    $fields = fieldsFor($application);

    expect($fields['difficulty__inclusivity-difficulty']->value)->toBe('AE')
        ->and($fields['difficulty__mtb-difficulty']->value)->toBe('BC')
        ->and($fields['difficulty__hiking-difficulty']->value)->toBeNull();
});

it('lascia vuota la famiglia se trekking e hiking propongono livelli diversi', function () {
    seedDifficultyFamilies();
    $application = TrailApplication::factory()->create(['api_data' => [
        'activities' => ['hiking', 'trekking'],
        'difficulty' => ['hiking' => 'E', 'trekking' => 'EEA'],
        'primary_activity' => 'hiking',
    ]]);

    expect(fieldsFor($application)['difficulty__hiking-difficulty']->value)->toBeNull();
});

it('tiene il livello se trekking e hiking concordano', function () {
    seedDifficultyFamilies();
    $application = TrailApplication::factory()->create(['api_data' => [
        'activities' => ['hiking', 'trekking'],
        'difficulty' => ['hiking' => 'E', 'trekking' => 'E'],
        'primary_activity' => 'hiking',
    ]]);

    expect(fieldsFor($application)['difficulty__hiking-difficulty']->value)->toBe('E');
});

it('ignora un livello che la scala non ha', function () {
    seedDifficultyFamilies();
    $application = TrailApplication::factory()->create(['api_data' => [
        'activities' => ['mtb'], 'difficulty' => ['mtb' => 'ZZ'], 'primary_activity' => 'mtb',
    ]]);

    expect(fieldsFor($application)['difficulty__mtb-difficulty']->value)->toBeNull();
});

it('nasconde le famiglie senza attivita selezionata e le mostra al cambio', function () {
    seedDifficultyFamilies();
    $application = TrailApplication::factory()->create(['api_data' => [
        'activities' => ['mtb'], 'primary_activity' => 'mtb',
    ]]);

    $fields = fieldsFor($application);
    $mtb = $fields['difficulty__mtb-difficulty'];
    $hiking = $fields['difficulty__hiking-difficulty'];

    expect($mtb->visible)->toBeTrue()->and($hiking->visible)->toBeFalse();

    // Il dependsOn registrato dal campo: lo si esegue come fa Nova al cambio di `activities`.
    $request = NovaRequest::create('/nova-api/trail-applications/actions', 'PATCH', ['resources' => [$application->id]]);
    $hiking->applyDependsOn($request->merge(['activities' => json_encode(['hiking'])]));

    expect($hiking->visible)->toBeTrue();
});

it('consegna activities come array di identifier dopo il fill', function () {
    // Contratto per il Task 4: ActionFields e' un Fluent, e Multiselect su un
    // modello senza getCasts() fa json_encode del valore. La request porta
    // l'array come lo manda il form (`activities[0]`, `activities[1]`).
    $request = NovaRequest::create('/nova-api/trail-applications/actions', 'POST', [
        'activities' => ['hiking', 'mtb'],
    ]);
    $field = (new ApproveTrailApplication)->fields($request)[0];
    $target = new Fluent;

    $field->fillInto($request, $target, 'activities');

    expect($target->activities)->toBe(['hiking', 'mtb']);
});

it('la primaria offre solo le attivita selezionate, con activities come array', function () {
    $application = TrailApplication::factory()->create(['api_data' => null]);
    $primary = fieldsFor($application)['primary_activity'];
    $request = NovaRequest::create('/nova-api/trail-applications/actions', 'PATCH', ['resources' => [$application->id]]);

    $primary->applyDependsOn($request->merge(['activities' => ['mtb', 'horse']]));

    expect(activityOptionKeys($primary))->toBe(['mtb', 'horse']);
});

it('la primaria offre solo le attivita selezionate, con activities come stringa JSON', function () {
    $application = TrailApplication::factory()->create(['api_data' => null]);
    $primary = fieldsFor($application)['primary_activity'];
    $request = NovaRequest::create('/nova-api/trail-applications/actions', 'PATCH', ['resources' => [$application->id]]);

    $primary->applyDependsOn($request->merge(['activities' => json_encode(['hiking'])]));

    expect(activityOptionKeys($primary))->toBe(['hiking']);
});

it('la famiglia hiking resta nascosta se si seleziona solo mtb', function () {
    seedDifficultyFamilies();
    $application = TrailApplication::factory()->create(['api_data' => null]);
    $hiking = fieldsFor($application)['difficulty__hiking-difficulty'];
    $request = NovaRequest::create('/nova-api/trail-applications/actions', 'PATCH', ['resources' => [$application->id]]);

    $hiking->applyDependsOn($request->merge(['activities' => ['mtb']]));

    expect($hiking->visible)->toBeFalse();
});

it('esige la primaria: senza, l approvazione non parte', function () {
    // Trovato provando a mano (oc:9038): togliendo dalle attivita' quella
    // prefillata come primaria, la select si svuota da se' — le sue opzioni
    // sono le attivita' selezionate — e l'approvazione passava lasciando il
    // sentiero senza attivita' primaria.
    $application = TrailApplication::factory()->create(['api_data' => null]);
    $request = ActionRequest::create('/nova-api/trail-applications/action', 'POST', [
        'resources' => (string) $application->id,
        'activities' => ['hiking'],
        'primary_activity' => '',
    ]);

    expect(fn () => (new ApproveTrailApplication)->validateFields($request))
        ->toThrow(ValidationException::class);
});

it('accetta la primaria scelta fra le attivita selezionate', function () {
    $application = TrailApplication::factory()->create(['api_data' => null]);
    $request = ActionRequest::create('/nova-api/trail-applications/action', 'POST', [
        'resources' => (string) $application->id,
        'activities' => ['hiking'],
        'primary_activity' => 'hiking',
    ]);

    expect((new ApproveTrailApplication)->validateFields($request))
        ->toHaveKey('primary_activity', 'hiking');
});
