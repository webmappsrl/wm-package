<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication as TrailApplicationModel;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication;

/**
 * Gli allegati della domanda si leggono nella detail view.
 *
 * Entrano solo dal canale API, una chiamata per allegato, e la Resource e'
 * l'unico posto dove l'istruttore puo' vederli: senza questi campi avrebbe
 * davanti i dati dichiarati ma non i documenti su cui la domanda si fonda.
 *
 * I nomi dei Field si verificano sulla label e non sull'attributo: sono Field
 * calcolati, senza una colonna dietro.
 */
beforeEach(function () {
    runTrailRegistryStubs();
    config(['wm-package.features.trail_registry.enabled' => true]);

    // Stesso setup degli altri test sugli allegati (vedi StoreAttachmentTest):
    // un media ha una app_id con vincolo di chiave esterna, e `Bus::fake()`
    // tiene l'App fuori dal registro SFTP condiviso.
    Bus::fake();
    DB::statement('ALTER SEQUENCE apps_id_seq RESTART WITH 1');
    config(['wm-package.shard_name' => 'wm_package_testing']);
    App::factory()->createQuietly();
    Storage::fake(config('media-library.disk_name', 'wmfe'));
    Storage::fake('wmfe');
    Storage::fake('public');
});

/**
 * Tutti i Field della Resource, anche quelli annidati dentro i Tab.
 *
 * Ha un nome proprio e non riusa quella di TrailApplicationApiDataFieldsTest:
 * due file di test che dichiarano la stessa funzione si rompono a vicenda
 * quando la suite li carica entrambi.
 *
 * @return list<Field>
 */
function campiDellaResourceConAllegati(array $fields): array
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
 * Il testo reso da un Field, cercato per label fra tutti i livelli di Tab.
 */
function allegatoReso(TrailApplication $resource, string $label): string
{
    $field = collect(campiDellaResourceConAllegati($resource->fields(app(NovaRequest::class))))
        ->first(fn ($f) => (string) $f->name === $label);

    expect($field)->not->toBeNull("Manca il Field «{$label}»");

    $field->resolveForDisplay($resource->resource);

    return (string) $field->value;
}

it('ha un campo per ciascuna delle cinque collection di allegati', function () {
    $resource = new TrailApplication(TrailApplicationModel::factory()->create());

    $labels = collect(campiDellaResourceConAllegati($resource->fields(app(NovaRequest::class))))
        ->map(fn ($f) => (string) $f->name)
        ->all();

    expect($labels)->toContain(__('Report'))
        ->and($labels)->toContain(__('Trail data sheet'))
        ->and($labels)->toContain(__('Authorizations'))
        ->and($labels)->toContain(__('Maintenance plan'))
        ->and($labels)->toContain(__('Photographs'));
});

it('dice «non trasmesso» per una collection vuota invece di sparire', function () {
    // Un campo che sparisce si confonde con un campo che non esiste: a chi
    // istruisce la domanda serve sapere che l'allegato manca.
    $resource = new TrailApplication(TrailApplicationModel::factory()->create());

    expect(allegatoReso($resource, __('Report')))->toContain(__('Not provided'));
});

it('mostra un documento come collegamento col nome del file', function () {
    $application = TrailApplicationModel::factory()->create();
    $application->addMedia(UploadedFile::fake()->create('relazione.pdf', 10, 'application/pdf'))
        ->toMediaCollection(TrailApplicationModel::REPORT_COLLECTION);

    $reso = allegatoReso(new TrailApplication($application->fresh()), __('Report'));

    expect($reso)->toContain('relazione.pdf')
        ->and($reso)->toContain('<a ')
        ->and($reso)->not->toContain('<img ');
});

it('mostra una fotografia come anteprima', function () {
    $application = TrailApplicationModel::factory()->create();
    $application->addMedia(UploadedFile::fake()->image('rilievo.jpg'))
        ->toMediaCollection(TrailApplicationModel::PHOTOS_COLLECTION);

    $reso = allegatoReso(new TrailApplication($application->fresh()), __('Photographs'));

    expect($reso)->toContain('<img ')
        ->and($reso)->toContain('rilievo.jpg');
});

it('mostra tutte le fotografie, non solo la prima', function () {
    $application = TrailApplicationModel::factory()->create();
    foreach (['uno.jpg', 'due.jpg', 'tre.jpg'] as $nome) {
        $application->addMedia(UploadedFile::fake()->image($nome))
            ->toMediaCollection(TrailApplicationModel::PHOTOS_COLLECTION);
    }

    $reso = allegatoReso(new TrailApplication($application->fresh()), __('Photographs'));

    expect(substr_count($reso, '<img '))->toBe(3);
});

it('non mette gli allegati nel form di modifica', function () {
    // Sola lettura come il resto di cio' che arriva dal canale: un allegato
    // sbagliato non si sostituisce dall'ufficio, si rifa' la domanda.
    $application = TrailApplicationModel::factory()->create();
    $application->addMedia(UploadedFile::fake()->image('rilievo.jpg'))
        ->toMediaCollection(TrailApplicationModel::PHOTOS_COLLECTION);

    $resource = new TrailApplication($application->fresh());

    $labels = collect(campiDellaResourceConAllegati($resource->fieldsForUpdate(app(NovaRequest::class))))
        ->map(fn ($f) => (string) $f->name)
        ->all();

    expect($labels)->not->toContain(__('Photographs'))
        ->and($labels)->not->toContain(__('Report'));
});
