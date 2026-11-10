<?php

use Illuminate\Support\Facades\App;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication as TrailApplicationModel;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication;

/**
 * I dati che arrivano dal SUS si leggono nella detail view e non si modificano
 * da nessuna parte (oc:8960): stanno fuori dai form per costruzione, con
 * onlyOnDetail(), non per un attributo di sola lettura che qualcuno potrebbe
 * togliere. I Field li costruisce PropertiesPanel da uno schema.
 */
beforeEach(function () {
    runTrailRegistryStubs();
    config(['wm-package.features.trail_registry.enabled' => true]);
});

/**
 * Tutti i Field della Resource, anche quelli annidati dentro i Tab.
 *
 * @return list<Field>
 */
function campiDiOgniLivello(array $fields): array
{
    return collect($fields)
        ->flatMap(function ($field) {
            if ($field instanceof Field) {
                return [$field];
            }

            return collect($field->data ?? [])
                ->flatMap(fn ($inner) => collect($inner->data ?? [$inner])->all())
                ->all();
        })
        ->filter(fn ($field) => $field instanceof Field)
        ->values()
        ->all();
}

/**
 * Gli attributi di tutti i Field della Resource, anche quelli dentro i Tab.
 *
 * @return list<string>
 */
function attributiDeiCampi(array $fields): array
{
    return collect(campiDiOgniLivello($fields))
        ->pluck('attribute')
        ->filter()
        ->map(fn ($attribute) => (string) $attribute)
        ->values()
        ->all();
}

it('mostra i dati del SUS nella detail view', function () {
    $resource = new TrailApplication(TrailApplicationModel::factory()->create());

    $attributi = attributiDeiCampi($resource->fields(app(NovaRequest::class)));

    expect($attributi)->toContain('api_data->from')
        ->and($attributi)->toContain('api_data->soggetto_gestore')
        ->and($attributi)->toContain('api_data->maintenance_frequency_months');
});

it('non mette i dati del SUS nel form di modifica', function () {
    $resource = new TrailApplication(TrailApplicationModel::factory()->create());

    $attributi = attributiDeiCampi($resource->fieldsForUpdate(app(NovaRequest::class)));

    expect(array_filter($attributi, fn ($a) => str_contains($a, 'api_data')))->toBeEmpty();
});

it('apre il dettaglio di un istanza senza api_data', function () {
    // E' il caso dell'istanza creata d'ufficio in Nova: api_data non esiste,
    // perche' quel contenuto arriva solo dal canale API. I Field vanno
    // risolti, non solo costruiti: e' la risoluzione che il detail fa.
    $application = TrailApplicationModel::factory()->create();
    $resource = new TrailApplication($application);

    expect(fn () => risolviOgniCampo($resource, $application))->not->toThrow(Throwable::class);
});

it('mostra l etichetta di una select, non il valore', function () {
    // Lo schema porta i valori ammessi del campo 38 con la loro etichetta, in
    // italiano e in inglese: l'operatore legge «c'e' ed e' fatiscente», non
    // `dilapidated`. L'etichetta finisce in `displayedAs`, non in `value`:
    // e' li' che Nova mette il risultato di displayUsing (oc:8960).
    App::setLocale('it');

    $application = TrailApplicationModel::factory()->create();
    $application->writeApiData(['existing_signage' => 'dilapidated']);

    $fresh = $application->fresh();
    $campo = collect(campiDiOgniLivello((new TrailApplication($fresh))->fields(app(NovaRequest::class))))
        ->first(fn ($field) => $field->attribute === 'api_data->existing_signage');

    $campo->resolveForDisplay($fresh);

    expect($campo->jsonSerialize()['displayedAs'])->toBe("c'e' ed e' fatiscente");
});

it('regge un valore annidato senza rompere la detail view', function () {
    // Engineering puo' mandare la coppia denominazione+codice fiscale come
    // lista di oggetti, o la difficolta' come mappa di liste. Concatenando i
    // valori a mano il primo caso mandava la detail view in 500 («Array to
    // string conversion») e il secondo rendeva una stringa vuota; PropertiesPanel
    // li rende come JSON (oc:8960).
    $application = TrailApplicationModel::factory()->create();
    $application->writeApiData([
        'soggetto_manutentore_cf' => [['denominazione' => 'Comune di Baunei', 'cf' => 'RSSMRA80A01H501U']],
        'difficulty' => ['hiking' => ['E', 'EE']],
        'from' => ['annidato' => ['A', 'B']],
    ]);

    $fresh = $application->fresh();

    expect(fn () => risolviOgniCampo(new TrailApplication($fresh), $fresh))
        ->not->toThrow(Throwable::class);
});

/**
 * Costruisce e risolve ogni Field, come fa Nova nel detail.
 */
function risolviOgniCampo(TrailApplication $resource, TrailApplicationModel $model): void
{
    foreach (campiDiOgniLivello($resource->fields(app(NovaRequest::class))) as $field) {
        $field->resolve($model);
        $field->jsonSerialize();
    }
}
