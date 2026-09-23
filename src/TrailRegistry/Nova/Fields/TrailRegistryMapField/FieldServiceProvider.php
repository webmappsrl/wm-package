<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Fields\TrailRegistryMapField;

use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Nova;

/**
 * Il bundle del campo `TrailRegistryMap`.
 *
 * Esiste dal momento in cui la mappa del registro ha smesso di disegnare
 * «quello di sempre» e ha avuto bisogno delle etichette sui sentieri vicini
 * (oc:8568): vedi il docblock di {@see TrailRegistryMap}.
 */
class FieldServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Nova::serving(function (ServingNova $event) {
            Nova::script('trail-registry-map', __DIR__.'/dist/js/field.js');
            Nova::style('trail-registry-map', __DIR__.'/dist/css/field.css');
        });
    }

    public function register(): void
    {
        //
    }
}
