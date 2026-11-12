<?php

namespace Wm\WmPackage\Http\Controllers\Api\TrailRegistry;

use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * La domanda nella classe che lo shard ha configurato.
 *
 * Il route model binding segue il type-hint del controller, che e' la classe
 * base: su uno shard che configura una propria sottoclasse
 * (`wm-package.features.trail_registry.models.application`), la domanda
 * arriverebbe al controller come istanza della base. Il `booted()` della
 * sottoclasse non scatterebbe, e gli allegati verrebbero registrati sotto il
 * nome della base — `TrailApplication::getMorphClass()` restituisce il nome di
 * classe reale — dove Nova, che lavora con la sottoclasse, non li troverebbe.
 *
 * Si rilegge solo quando la classe configurata e' davvero diversa: senza
 * sottoclasse, nessuna query in piu'. Il binding non si sostituisce con un
 * `Route::model('application', ...)`, perche' legherebbe per tutta
 * l'applicazione un nome di parametro generico.
 */
trait ResolvesConfiguredApplication
{
    protected function configuredApplication(TrailApplication $application): TrailApplication
    {
        $class = TrailRegistryClasses::application();

        if ($application instanceof $class) {
            return $application;
        }

        return $class::query()->findOrFail($application->getKey());
    }
}
