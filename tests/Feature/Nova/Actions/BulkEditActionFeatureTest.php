<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Tests\TestCase;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Nova\Actions\BulkEditAction;

uses(TestCase::class, DatabaseTransactions::class);

// Resource fixture locale per i test feature
class BulkEditFeatureTestResource extends Resource
{
    public static $model = EcPoi::class;

    public function fields(NovaRequest $request): array
    {
        return [
            Text::make('Name', 'name'),
            Boolean::make('Global', 'global'),
        ];
    }
}

// Fixture con campi properties->* per testare la preservazione dei valori esistenti
class BulkEditPropertiesTestResource extends Resource
{
    public static $model = EcPoi::class;

    public function fields(NovaRequest $request): array
    {
        return [
            Text::make('Contact Email', 'properties->contact_email'),
            Text::make('House Number', 'properties->addr_housenumber'),
            Text::make('Locality', 'properties->addr_locality'),
        ];
    }
}

function makeActionFields(array $data): ActionFields
{
    return new ActionFields(collect($data), collect());
}

it('aggiorna il campo global quando valorizzato', function () {
    $poi = EcPoi::factory()->createQuietly(['properties' => [], 'global' => false]);

    $fields = makeActionFields(['global' => true, 'name' => null]);

    $action = new BulkEditAction(BulkEditFeatureTestResource::class);
    $action->handle($fields, collect([$poi]));

    expect($poi->fresh()->global)->toBeTrue();
});

it('skippa i valori null e non sovrascrive il campo esistente', function () {
    $poi = EcPoi::factory()->createQuietly(['properties' => [], 'global' => true]);
    $originalGlobal = $poi->global;

    $fields = makeActionFields(['global' => null]);

    $action = new BulkEditAction(BulkEditFeatureTestResource::class);
    $action->handle($fields, collect([$poi]));

    expect($poi->fresh()->global)->toBe($originalGlobal);
});

it('skippa i valori stringa vuota e non sovrascrive il campo esistente', function () {
    $poi = EcPoi::factory()->createQuietly(['properties' => [], 'name' => ['it' => 'Nome originale']]);

    $fields = makeActionFields(['name' => '']);

    $action = new BulkEditAction(BulkEditFeatureTestResource::class);
    $action->handle($fields, collect([$poi]));

    expect($poi->fresh()->getTranslation('name', 'it'))->toBe('Nome originale');
});

it('salva il valore false per un campo boolean', function () {
    $poi = EcPoi::factory()->createQuietly(['properties' => [], 'global' => true]);

    $fields = makeActionFields(['global' => false]);

    $action = new BulkEditAction(BulkEditFeatureTestResource::class);
    $action->handle($fields, collect([$poi]));

    expect($poi->fresh()->global)->toBeFalse();
});

it('salva il valore true per un campo boolean', function () {
    $poi = EcPoi::factory()->createQuietly(['properties' => [], 'global' => false]);

    $fields = makeActionFields(['global' => true]);

    $action = new BulkEditAction(BulkEditFeatureTestResource::class);
    $action->handle($fields, collect([$poi]));

    expect($poi->fresh()->global)->toBeTrue();
});

it('aggiorna più modelli nella stessa transazione', function () {
    $poi1 = EcPoi::factory()->createQuietly(['properties' => [], 'global' => false]);
    $poi2 = EcPoi::factory()->createQuietly(['properties' => [], 'global' => false]);

    $fields = makeActionFields(['global' => true]);

    $action = new BulkEditAction(BulkEditFeatureTestResource::class);
    $action->handle($fields, collect([$poi1, $poi2]));

    expect($poi1->fresh()->global)->toBeTrue();
    expect($poi2->fresh()->global)->toBeTrue();
});

it('non sovrascrive il boolean esistente se la select viene lasciata vuota (null)', function () {
    $poi = EcPoi::factory()->createQuietly(['properties' => [], 'global' => true]);

    // Null = nessuna selezione nella Select tri-state = nessuna modifica
    $fields = makeActionFields(['global' => null]);

    $action = new BulkEditAction(BulkEditFeatureTestResource::class);
    $action->handle($fields, collect([$poi]));

    expect($poi->fresh()->global)->toBeTrue();
});

it('preserva i valori properties->* esistenti quando si modifica un solo campo', function () {
    $poi = EcPoi::factory()->createQuietly([
        'properties' => [
            'contact_email' => 'test@example.com',
            'addr_housenumber' => '5',
            'addr_locality' => 'Roma',
        ],
    ]);

    // BulkEdit solo addr_housenumber; contact_email e addr_locality vengono lasciati null
    $fields = makeActionFields([
        'properties->addr_housenumber' => '123',
        'properties->contact_email' => null,
        'properties->addr_locality' => null,
    ]);

    $action = new BulkEditAction(BulkEditPropertiesTestResource::class);
    $action->handle($fields, collect([$poi]));

    $fresh = $poi->fresh();
    expect($fresh->properties['addr_housenumber'])->toBe('123');
    expect($fresh->properties['contact_email'])->toBe('test@example.com');
    expect($fresh->properties['addr_locality'])->toBe('Roma');
});

it('preserva i valori properties->* quando il campo viene lasciato come stringa vuota', function () {
    $poi = EcPoi::factory()->createQuietly([
        'properties' => [
            'contact_email' => 'test@example.com',
            'addr_housenumber' => '5',
        ],
    ]);

    // Empty string per contact_email — deve essere skippato
    $fields = makeActionFields([
        'properties->addr_housenumber' => '999',
        'properties->contact_email' => '',
    ]);

    $action = new BulkEditAction(BulkEditPropertiesTestResource::class);
    $action->handle($fields, collect([$poi]));

    $fresh = $poi->fresh();
    expect($fresh->properties['addr_housenumber'])->toBe('999');
    expect($fresh->properties['contact_email'])->toBe('test@example.com');
});

it('modifica più properties->* nello stesso bulk edit senza perdere le chiavi non toccate', function () {
    $poi = EcPoi::factory()->createQuietly([
        'properties' => [
            'contact_email' => 'original@example.com',
            'addr_housenumber' => '5',
            'addr_locality' => 'Roma',
        ],
    ]);

    // L'utente modifica addr_housenumber E addr_locality; contact_email rimane null
    $fields = makeActionFields([
        'properties->addr_housenumber' => '999',
        'properties->addr_locality' => 'Milano',
        'properties->contact_email' => null,
    ]);

    $action = new BulkEditAction(BulkEditPropertiesTestResource::class);
    $action->handle($fields, collect([$poi]));

    $fresh = $poi->fresh();
    expect($fresh->properties['addr_housenumber'])->toBe('999');
    expect($fresh->properties['addr_locality'])->toBe('Milano');
    expect($fresh->properties['contact_email'])->toBe('original@example.com');
});

it('preserva i valori properties->* quando un campo KeyValue invia array vuoto', function () {
    $poi = EcPoi::factory()->createQuietly([
        'properties' => [
            'contact_email' => 'test@example.com',
            'addr_housenumber' => '5',
            'related_url' => ['chiave' => 'https://example.com'],
        ],
    ]);

    // KeyValue vuoto invia [] — deve essere skippato per evitare sovrascrittura
    $fields = makeActionFields([
        'properties->addr_housenumber' => '999',
        'properties->contact_email' => null,
        'properties->related_url' => [],
    ]);

    $action = new BulkEditAction(BulkEditPropertiesTestResource::class);
    $action->handle($fields, collect([$poi]));

    $fresh = $poi->fresh();
    expect($fresh->properties['addr_housenumber'])->toBe('999');
    expect($fresh->properties['contact_email'])->toBe('test@example.com');
    // related_url NON deve essere svuotato: [] è "nessuna modifica", non "cancella"
    expect($fresh->properties['related_url'])->toBe(['chiave' => 'https://example.com']);
});

it('preserva sibling quando Nova invia properties come oggetto annidato', function () {
    $poi = EcPoi::factory()->createQuietly([
        'properties' => [
            'addr_housenumber' => '123',
            'contact_email' => null,
            'related_url' => [],
        ],
        'global' => false,
    ]);

    // Struttura reale inviata da Nova: i campi properties->* arrivano annidati
    // sotto la chiave 'properties', non come chiavi letterali 'properties->*'.
    $fields = makeActionFields([
        'properties' => [
            'show_image_on_map' => null,
            'contact_email' => 'test@test.test',
            'contact_phone' => null,
            'opening_hours' => null,
            'addr_locality' => null,
            'addr_housenumber' => null,
            'related_url' => [],
            'addr_complete' => null,
        ],
        'global' => '1',
    ]);

    $action = new BulkEditAction(BulkEditPropertiesTestResource::class);
    $action->handle($fields, collect([$poi]));

    $fresh = $poi->fresh();
    expect($fresh->properties['contact_email'])->toBe('test@test.test');
    expect($fresh->properties['addr_housenumber'])->toBe('123');
});

it('fa rollback di tutte le modifiche se un save fallisce', function () {
    $poi1 = EcPoi::factory()->createQuietly(['properties' => [], 'global' => false]);
    $poi2 = EcPoi::factory()->createQuietly(['properties' => [], 'global' => false]);

    // Forza un'eccezione DB durante la transazione
    DB::shouldReceive('transaction')->once()->andThrow(new RuntimeException('DB error'));

    $fields = makeActionFields(['global' => true]);

    $action = new BulkEditAction(BulkEditFeatureTestResource::class);

    expect(fn () => $action->handle($fields, collect([$poi1, $poi2])))
        ->toThrow(RuntimeException::class);

    // I record non devono essere modificati
    expect($poi1->fresh()->global)->toBeFalse();
    expect($poi2->fresh()->global)->toBeFalse();
});
