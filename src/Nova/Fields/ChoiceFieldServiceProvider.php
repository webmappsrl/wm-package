<?php

namespace Wm\WmPackage\Nova\Fields;

use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Nova;

/**
 * Registra il componente della select di opzioni (oc:8910).
 *
 * Sta qui e non dentro la cartella del campo perché il PSR-4 di base (`Wm\WmPackage\` → `src/`)
 * copre già `src/Nova/Fields/`: una voce nuova in `composer.json` lascerebbe gli shard con una
 * classe non trovata a ogni pagina di Nova finché non rifanno `composer update`.
 */
class ChoiceFieldServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Nova::serving(function (ServingNova $event) {
            Nova::mix('choice', __DIR__.'/ChoiceField/dist/mix-manifest.json');
        });
    }

    public function register(): void
    {
        //
    }
}
