<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Layer as LayerResource;

uses(DatabaseTransactions::class);

/*
 * Il Field «Web Component» della Resource Layer: visibile solo col flag dell'App acceso, copia
 * lo snippet del layer senza `track-id` (oc:7642, oc:8590). Snippet e bottone stanno in
 * LayerMapWebComponentService, coperto da LayerMapWebComponentServiceTest (oc:8944).
 *
 * Riscritto in oc:8944: la versione precedente usava le classi dello shard (`Tests\TestCase`,
 * `App\Nova\Layer`) e non girava in nessuna suite.
 */

beforeEach(function () {
    Queue::fake();
    Http::fake();
    config(['wm-package.shard_name' => 'camminiditalia']);
});

function layerWebComponentField(bool $enabled): array
{
    $app = App::factory()->createQuietly(['properties' => ['layer_web_component_enabled' => $enabled]]);
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id])->fresh();
    $request = NovaRequest::create('/');

    $field = collect((new LayerResource($layer))->fields($request))
        ->first(fn ($field) => $field instanceof Text && $field->name === __('Web Component'));

    return [$field, $layer, $request];
}

it('shows the copy button only when the app flag is on', function () {
    [$enabledField, , $request] = layerWebComponentField(true);
    [$disabledField] = layerWebComponentField(false);

    expect($enabledField->authorizedToSee($request))->toBeTrue()
        ->and($disabledField->authorizedToSee($request))->toBeFalse();
});

it('copies the layer snippet without track-id and without a select', function () {
    [$field, $layer] = layerWebComponentField(true);

    $field->resolveForDisplay($layer);

    expect($field->value)
        ->toContain('layer-id=&quot;'.$layer->id.'&quot;')
        ->toContain('https://html.onlineviewer.net/')
        ->not->toContain('track-id')
        ->not->toContain('<select');
});
