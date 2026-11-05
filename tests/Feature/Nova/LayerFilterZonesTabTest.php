<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\TabsGroup;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Nova\Fields\Choice\Choice;
use Wm\WmPackage\Nova\Layer as LayerResource;

/**
 * Le zone sono un filtro del layer come gli altri, e si scelgono allo stesso modo (oc:8910).
 *
 * `LayerService` le ha sempre trattate come attività e termini — in AND con loro — ma il campo
 * stava fuori dalla sezione «Filtri», con una forma tutta sua: la frase che spiega la regola
 * non le copriva e chi configurava un layer non le riconosceva come criterio.
 */
uses(DatabaseTransactions::class);

function layerFilterLayer(): Layer
{
    App::firstOr(fn () => App::factory()->createQuietly());

    return Layer::factory()->createQuietly();
}

function layerFilterFields(): array
{
    $layer = layerFilterLayer();
    $resource = new LayerResource($layer);
    $request = NovaRequest::create('/nova-api/layers/'.$layer->id, 'GET');

    foreach ($resource->fields($request) as $field) {
        if ($field instanceof TabsGroup) {
            return $field->data;
        }
    }

    return [];
}

function layerFilterZoneChoice(): ?Choice
{
    return collect(layerFilterFields())
        ->first(fn ($field) => $field instanceof Choice && $field->attribute === 'legacy_taxonomy_wheres');
}

it('mette le zone dentro la sezione dei filtri', function () {
    expect(layerFilterZoneChoice())->not->toBeNull();
});

it('le offre come select multipla, come gli altri filtri', function () {
    TaxonomyWhere::query()->create(['name' => ['it' => 'Zona di prova'], 'identifier' => 'zona-'.uniqid()]);

    $zone = layerFilterZoneChoice();

    expect($zone->meta()['multiple'] ?? null)->toBeTrue()
        ->and($zone->meta()['options'] ?? [])->not->toBeEmpty();
});

it('non lascia le zone come campo a sé fuori dalla sezione', function () {
    $layer = layerFilterLayer();
    $resource = new LayerResource($layer);
    $request = NovaRequest::create('/nova-api/layers/'.$layer->id, 'GET');

    $outside = collect($resource->fields($request))
        ->first(fn ($field) => $field instanceof MorphToMany && $field->attribute === 'taxonomyWheres');

    expect($outside)->toBeNull();
});

it('sta nel tab di default, insieme agli altri criteri con cui si definisce un layer', function () {
    $fields = layerFilterFields();

    $zone = collect($fields)->search(fn ($f) => $f instanceof Choice && $f->attribute === 'legacy_taxonomy_wheres');
    // «Attività» apre il tab successivo: le zone devono venire prima.
    $activity = collect($fields)->search(fn ($f) => $f instanceof Choice && $f->attribute === 'legacy_taxonomy_activities');

    expect($zone)->not->toBeFalse();

    if ($activity !== false) {
        expect($zone)->toBeLessThan($activity);
    }
});
