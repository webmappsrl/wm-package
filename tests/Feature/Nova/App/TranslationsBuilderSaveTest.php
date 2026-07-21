<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\App as AppModel;
use Wm\WmPackage\Nova\App as AppResource;
use Wm\WmPackage\Nova\Fields\TranslationsBuilder\TranslationsBuilder;
use Wm\WmPackage\Services\Models\App\AppConfigService;

uses(TestCase::class, DatabaseTransactions::class);

function resolveTranslationsBuilderField(AppModel $app): TranslationsBuilder
{
    $resource = new AppResource($app);

    $ref = new ReflectionMethod($resource, 'translations_tab');
    $ref->setAccessible(true);
    $fields = $ref->invoke($resource);

    return collect($fields)->first(fn ($f) => $f instanceof TranslationsBuilder);
}

it('a real Nova save through App::translations_tab() is reflected in config.json', function () {
    $app = AppModel::factory()->createQuietly([
        'translations_it' => null,
        'translations_en' => null,
    ]);

    $field = resolveTranslationsBuilderField($app);

    $payload = json_encode([
        'it' => ['welcome' => 'Benvenuto'],
        'en' => ['welcome' => 'Welcome'],
    ]);

    $request = NovaRequest::create('/', 'POST', [
        'translations_builder' => $payload,
    ]);

    $field->fill($request, $app);
    $app->save();
    $app->refresh();

    $config = (new AppConfigService($app))->config();

    expect($config['TRANSLATIONS'])->toBe([
        'it' => ['welcome' => 'Benvenuto'],
        'en' => ['welcome' => 'Welcome'],
    ]);
});

it('a bulk-style update that only touches one language leaves the other language untouched end-to-end', function () {
    $app = AppModel::factory()->createQuietly([
        'translations_it' => ['existing' => 'valore esistente'],
        'translations_en' => ['existing' => 'existing value'],
    ]);

    $field = resolveTranslationsBuilderField($app);

    $payload = json_encode([
        'it' => ['existing' => 'valore esistente', 'new_key' => 'nuovo valore'],
    ]);

    $request = NovaRequest::create('/', 'POST', [
        'translations_builder' => $payload,
    ]);

    $field->fill($request, $app);
    $app->save();
    $app->refresh();

    $config = (new AppConfigService($app))->config();

    expect($config['TRANSLATIONS'])->toBe([
        'it' => ['existing' => 'valore esistente', 'new_key' => 'nuovo valore'],
        'en' => ['existing' => 'existing value'],
    ]);
});
