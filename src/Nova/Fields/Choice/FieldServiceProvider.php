<?php

namespace Wm\WmPackage\Nova\Fields\Choice;

use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Nova;

/**
 * Registra il componente della select di opzioni (oc:8910).
 *
 * Non serve nessuna voce PSR-4 in `composer.json`: cartella e namespace combaciano, quindi la
 * regola di base (`Wm\WmPackage\` → `src/`) basta da sola, come per `TrackColor`.
 */
class FieldServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Nova::serving(function (ServingNova $event) {
            Nova::mix('choice', __DIR__.'/dist/mix-manifest.json');
        });
    }

    public function register(): void
    {
        //
    }
}
