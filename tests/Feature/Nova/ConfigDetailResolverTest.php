<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Flexible\Resolvers\ConfigDetailResolver;

uses(TestCase::class, DatabaseTransactions::class);

function probeConfigDetailField(): Flexible
{
    return Flexible::make('Config Detail', 'properties->config_detail')
        ->resolver(ConfigDetailResolver::class)
        ->addLayout('Probe', 'probe', [
            Text::make('Label', 'label'),
        ]);
}

function configDetailFillRequest(int $layerId, array $groups): NovaRequest
{
    $symfonyRequest = HttpRequest::create("/nova-api/layers/{$layerId}", 'PUT', [
        'properties->config_detail' => $groups,
    ]);

    return NovaRequest::createFrom($symfonyRequest, new NovaRequest);
}

it('round-trips a probe group and preserves sibling properties keys', function () {
    App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly([
        'properties' => ['title' => ['it' => 'Titolo esistente'], 'description' => ['it' => 'Descrizione esistente']],
    ]);

    $field = probeConfigDetailField();
    $request = configDetailFillRequest($layer->id, [
        ['layout' => 'probe', 'key' => 'new-group-key-1', 'attributes' => ['label' => 'Ciao']],
    ]);

    $callback = $field->fill($request, $layer);
    if (is_callable($callback)) {
        $callback();
    }
    $layer->save();

    $fresh = $layer->fresh();
    expect($fresh->properties['config_detail'])->toHaveCount(1);
    expect($fresh->properties['config_detail'][0]['box_type'])->toBe('probe');
    expect($fresh->properties['config_detail'][0]['label'])->toBe('Ciao');
    expect($fresh->properties['title'])->toBe(['it' => 'Titolo esistente']);
    expect($fresh->properties['description'])->toBe(['it' => 'Descrizione esistente']);
});

it('resolves a previously saved probe group back into the field value', function () {
    App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly([
        'properties' => [
            'title' => ['it' => 'Titolo'],
            'config_detail' => [
                ['box_type' => 'probe', 'label' => 'Valore salvato'],
            ],
        ],
    ]);

    $field = probeConfigDetailField();
    $field->resolve($layer);

    expect($field->value)->toHaveCount(1);
    $labelField = collect($field->value[0]['attributes'])->first(fn ($f) => $f['attribute'] === 'label');
    expect($labelField)->not->toBeNull();
    expect($labelField['value'])->toBe('Valore salvato');
});
