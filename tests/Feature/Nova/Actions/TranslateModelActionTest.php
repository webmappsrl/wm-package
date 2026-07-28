<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Jobs\TranslateModelJob;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\Actions\TranslateModelAction;
use Wm\WmPackage\Nova\EcTrack as EcTrackResource;

uses(RefreshDatabase::class);

function emptyActionFields(): ActionFields
{
    return new ActionFields(collect(), collect());
}

function protectedProperty(object $object, string $property): mixed
{
    return (function () use ($property) {
        return $this->{$property};
    })->call($object);
}

it('costruisce il nome dinamico dalla singularLabel della risorsa', function () {
    $action = new TranslateModelAction(EcTrackResource::class);

    expect($action->name())->toBe(__('Translate :label Contents', [
        'label' => EcTrackResource::singularLabel(),
    ]));
});

it('il confirmText di default elenca solo name e description', function () {
    $action = new TranslateModelAction(EcTrackResource::class);

    expect($action->confirmText)->toBe(__('Missing translations will be updated for the following fields: :fields', [
        'fields' => 'name, description',
    ]));
});

it('il confirmText include i campi extra configurati', function () {
    $action = new TranslateModelAction(EcTrackResource::class, [
        'not_accessible_message' => TranslateModelJob::DEFAULT_FIELD_RULE,
    ]);

    expect($action->confirmText)->toBe(__('Missing translations will be updated for the following fields: :fields', [
        'fields' => 'name, description, not_accessible_message',
    ]));
});

it('lancia InvalidArgumentException se additionalFieldRules contiene name o description', function () {
    expect(fn () => new TranslateModelAction(EcTrackResource::class, ['description' => 'x']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new TranslateModelAction(EcTrackResource::class, ['name' => 'x']))
        ->toThrow(InvalidArgumentException::class);
});

it('salta il dispatch quando tutti i campi abilitati sono già completi', function () {
    Bus::fake();

    $track = EcTrack::factory()->createQuietly([
        'name' => ['it' => 'Nome', 'en' => 'Name', 'de' => 'Name', 'fr' => 'Nom', 'es' => 'Nombre'],
        'properties' => [
            'description' => ['it' => 'Testo', 'en' => 'Text', 'de' => 'Text', 'fr' => 'Texte', 'es' => 'Texto'],
        ],
    ]);

    $action = new TranslateModelAction(EcTrackResource::class);
    $response = $action->handle(emptyActionFields(), collect([$track]));

    Bus::assertNotDispatched(TranslateModelJob::class);
    expect((string) $response['message'])->toBe(__('No fields to translate (already translated or missing Italian source).'));
});

it('dispatcha il job passando il dizionario dei campi extra e solo le lingue mancanti', function () {
    Bus::fake();

    $track = EcTrack::factory()->createQuietly([
        'name' => ['it' => 'Nome', 'en' => 'Name', 'de' => 'Name', 'fr' => 'Nom', 'es' => 'Nombre'],
        'properties' => [
            'description' => ['it' => 'Testo', 'en' => 'Text', 'de' => 'Text', 'fr' => 'Texte', 'es' => 'Texto'],
            'not_accessible_message' => ['it' => 'Non accessibile', 'en' => 'Not accessible'],
        ],
    ]);

    $additionalFieldRules = ['not_accessible_message' => TranslateModelJob::DEFAULT_FIELD_RULE];
    $action = new TranslateModelAction(EcTrackResource::class, $additionalFieldRules);
    $response = $action->handle(emptyActionFields(), collect([$track]));

    Bus::assertDispatched(TranslateModelJob::class, function (TranslateModelJob $job) use ($track, $additionalFieldRules) {
        $model = protectedProperty($job, 'model');
        $locales = protectedProperty($job, 'locales');
        $rules = protectedProperty($job, 'additionalFieldRules');

        return $model->is($track)
            && $locales === ['de', 'fr', 'es'] // solo not_accessible_message manca, en è già presente
            && $rules === $additionalFieldRules;
    });

    expect((string) $response['message'])->toBe(__(':dispatched translation jobs dispatched, :skipped models skipped.', [
        'dispatched' => 1,
        'skipped' => 0,
    ]));
});
