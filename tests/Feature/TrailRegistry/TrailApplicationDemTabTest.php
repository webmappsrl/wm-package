<?php

use Illuminate\Http\Request;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\AbstractGeometryResource;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication as TrailApplicationModel;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication as TrailApplicationResource;

beforeEach(function () {
    runTrailRegistryStubs();
});

function resourceFor(TrailApplicationStatus $status): TrailApplicationResource
{
    return new TrailApplicationResource(TrailApplicationModel::factory()->create(['status' => $status]));
}

it('estende la base geometrica del sentiero', function () {
    expect(is_subclass_of(TrailApplicationResource::class, AbstractGeometryResource::class))->toBeTrue();
});

it('si modifica solo in istruttoria', function () {
    $request = Request::create('/');

    expect(resourceFor(TrailApplicationStatus::UnderReview)->authorizedToUpdate($request))->toBeTrue()
        ->and(resourceFor(TrailApplicationStatus::Approved)->authorizedToUpdate($request))->toBeFalse()
        ->and(resourceFor(TrailApplicationStatus::Rejected)->authorizedToUpdate($request))->toBeFalse();
});

it('il form di modifica contiene solo i nove valori manuali', function () {
    $fields = collect(resourceFor(TrailApplicationStatus::UnderReview)->fieldsForUpdate(NovaRequest::create('/')))
        ->map(fn (Field $f) => $f->attribute);

    expect($fields)->toHaveCount(9)
        ->and($fields->every(fn ($a) => str_starts_with($a, 'properties->manual_data->')))->toBeTrue();
});

it('ogni valore manuale ha le regole decise per il suo campo', function () {
    // Elevazioni negative ammesse (quote sotto lo zero); le durate sono
    // minuti interi restituiti dal DEM; ascent/descent/distance non possono
    // essere negativi (oc:8571).
    $expectedRules = [
        'ascent' => ['nullable', 'numeric', 'min:0'],
        'descent' => ['nullable', 'numeric', 'min:0'],
        'distance' => ['nullable', 'numeric', 'min:0'],
        'ele_max' => ['nullable', 'numeric'],
        'ele_min' => ['nullable', 'numeric'],
        'ele_from' => ['nullable', 'numeric'],
        'ele_to' => ['nullable', 'numeric'],
        'duration_forward' => ['nullable', 'integer', 'min:0'],
        'duration_backward' => ['nullable', 'integer', 'min:0'],
    ];

    $fields = collect(resourceFor(TrailApplicationStatus::UnderReview)->fieldsForUpdate(NovaRequest::create('/')))
        ->mapWithKeys(fn (Field $f) => [str_replace('properties->manual_data->', '', $f->attribute) => $f->rules]);

    foreach ($expectedRules as $fieldKey => $rules) {
        expect($fields[$fieldKey])->toBe($rules);
    }
});

it('svuotare un manuale fa tornare il DEM', function () {
    $model = TrailApplicationModel::factory()->create([
        'properties' => ['dem_data' => ['ascent' => 300], 'manual_data' => ['ascent' => 350]],
    ]);
    $resource = new TrailApplicationResource($model);

    $properties = $model->properties;
    $properties['manual_data']['ascent'] = null;
    $model->properties = $properties;

    expect($resource->classifyField($model, 'ascent')['currentValue'])->toBe(300);
});

it('ogni valore manuale dichiara la sua unita', function () {
    $fields = collect(resourceFor(TrailApplicationStatus::UnderReview)->fieldsForUpdate(NovaRequest::create('/')))
        ->mapWithKeys(fn ($f) => [str_replace('properties->manual_data->', '', $f->attribute) => $f->helpText]);

    expect($fields['duration_forward'])->toBe(__('In minuti'))
        ->and($fields['distance'])->toBe(__('In km'))
        ->and($fields['ascent'])->toBe(__('In metri'));
});
