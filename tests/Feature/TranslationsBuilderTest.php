<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Nova\Fields\TranslationsBuilder\TranslationsBuilder;

uses(TestCase::class, DatabaseTransactions::class);

function invokeProtectedTranslationsBuilder(object $object, string $method, array $args = [])
{
    $ref = new ReflectionMethod($object, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($object, $args);
}

it('defaults langs to italian when an empty array is given', function () {
    $field = TranslationsBuilder::make('Translations', 'translations_builder')->langs([]);

    expect($field->meta['langs'])->toBe(['it']);
});

it('resolve reads both language columns from the model', function () {
    $app = App::factory()->createQuietly([
        'translations_it' => ['key' => 'valore'],
        'translations_en' => ['key' => 'value'],
    ]);

    $field = TranslationsBuilder::make('Translations', 'translations_builder')->langs(['it', 'en']);
    $field->resolve($app);

    expect($field->value)->toBe([
        'langs' => ['it', 'en'],
        'values' => [
            'it' => ['key' => 'valore'],
            'en' => ['key' => 'value'],
        ],
    ]);
});

it('fill writes both language columns on the model', function () {
    $app = App::factory()->createQuietly([
        'translations_it' => null,
        'translations_en' => null,
    ]);

    $field = TranslationsBuilder::make('Translations', 'translations_builder')->langs(['it', 'en']);

    $payload = json_encode([
        'it' => ['key' => 'valore'],
        'en' => ['key' => 'value'],
    ]);

    $request = NovaRequest::create('/', 'POST', [
        'translations_builder' => $payload,
    ]);

    invokeProtectedTranslationsBuilder($field, 'fillAttributeFromRequest', [$request, 'translations_builder', $app, 'translations_builder']);

    expect($app->translations_it)->toBe(['key' => 'valore'])
        ->and($app->translations_en)->toBe(['key' => 'value']);
});

it('fill leaves the other language column untouched when the payload only contains one language', function () {
    $app = App::factory()->createQuietly([
        'translations_it' => ['existing' => 'valore esistente'],
        'translations_en' => ['existing' => 'existing value'],
    ]);

    $field = TranslationsBuilder::make('Translations', 'translations_builder')->langs(['it', 'en']);

    $payload = json_encode([
        'it' => ['existing' => 'valore modificato'],
    ]);

    $request = NovaRequest::create('/', 'POST', [
        'translations_builder' => $payload,
    ]);

    invokeProtectedTranslationsBuilder($field, 'fillAttributeFromRequest', [$request, 'translations_builder', $app, 'translations_builder']);

    expect($app->translations_it)->toBe(['existing' => 'valore modificato'])
        ->and($app->translations_en)->toBe(['existing' => 'existing value']);
});

it('fill does not throw and leaves the model untouched when the payload is not a string', function () {
    $app = App::factory()->createQuietly([
        'translations_it' => ['existing' => 'valore esistente'],
        'translations_en' => null,
    ]);

    $field = TranslationsBuilder::make('Translations', 'translations_builder')->langs(['it', 'en']);

    // Simula una richiesta scriptata con notazione a bracket (translations_builder[it]=...),
    // che Laravel risolve come array invece che come stringa JSON.
    $request = NovaRequest::create('/', 'POST', [
        'translations_builder' => ['it' => 'valore'],
    ]);

    invokeProtectedTranslationsBuilder($field, 'fillAttributeFromRequest', [$request, 'translations_builder', $app, 'translations_builder']);

    expect($app->translations_it)->toBe(['existing' => 'valore esistente'])
        ->and($app->translations_en)->toBeNull();
});

it('fill does not throw and leaves the model untouched when the payload is invalid JSON', function () {
    $app = App::factory()->createQuietly([
        'translations_it' => ['existing' => 'valore esistente'],
        'translations_en' => null,
    ]);

    $field = TranslationsBuilder::make('Translations', 'translations_builder')->langs(['it', 'en']);

    $request = NovaRequest::create('/', 'POST', [
        'translations_builder' => 'not-valid-json',
    ]);

    invokeProtectedTranslationsBuilder($field, 'fillAttributeFromRequest', [$request, 'translations_builder', $app, 'translations_builder']);

    expect($app->translations_it)->toBe(['existing' => 'valore esistente'])
        ->and($app->translations_en)->toBeNull();
});

it('fill ignores a language whose payload value is not an array', function () {
    $app = App::factory()->createQuietly([
        'translations_it' => ['existing' => 'valore esistente'],
        'translations_en' => ['existing' => 'existing value'],
    ]);

    $field = TranslationsBuilder::make('Translations', 'translations_builder')->langs(['it', 'en']);

    $payload = json_encode([
        'it' => 'questo non è un array',
        'en' => ['existing' => 'updated value'],
    ]);

    $request = NovaRequest::create('/', 'POST', [
        'translations_builder' => $payload,
    ]);

    invokeProtectedTranslationsBuilder($field, 'fillAttributeFromRequest', [$request, 'translations_builder', $app, 'translations_builder']);

    expect($app->translations_it)->toBe(['existing' => 'valore esistente'])
        ->and($app->translations_en)->toBe(['existing' => 'updated value']);
});
