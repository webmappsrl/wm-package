<?php

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication as TrailApplicationModel;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailApplicationStatusFilter;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication;

/**
 * Lo stato che l'operatore legge e' tradotto, in tutti i posti dove compare
 * (oc:8960). La traduzione vive sull'enum: qui si verifica che elenco,
 * dettaglio e filtro la usino invece del valore grezzo.
 */
beforeEach(function () {
    runTrailRegistryStubs();
    config(['wm-package.features.trail_registry.enabled' => true]);
});

it('mostra lo stato tradotto, non il valore grezzo', function () {
    $application = TrailApplicationModel::factory()->create([
        'status' => TrailApplicationStatus::UnderCompilation,
    ]);
    $resource = new TrailApplication($application);

    $campo = collect($resource->fields(app(NovaRequest::class)))
        ->first(fn ($field) => $field->name === __('Review status'));

    $campo->resolve($application);

    expect($campo->value)->not->toBe('under_compilation')
        ->and($campo->value)->toBe(TrailApplicationStatus::UnderCompilation->label());
});

it('elenca gli stati tradotti nel filtro, filtrando sul valore', function () {
    $options = (new TrailApplicationStatusFilter)->options(app(NovaRequest::class));

    // La chiave e' cio' che l'operatore legge, il valore e' cio' su cui si filtra.
    expect(array_keys($options))->not->toContain('under_compilation')
        ->and(array_values($options))->toContain('under_compilation')
        ->and($options)->toHaveKey(TrailApplicationStatus::UnderCompilation->label());
});
