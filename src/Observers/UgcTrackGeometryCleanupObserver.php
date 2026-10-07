<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;

/**
 * Ricostruisce la geometria di una UgcTrack da properties.locations, scartando i punti GPS
 * inutilizzabili (oc:8719).
 *
 * Su `saving`, che Laravel emette prima di `creating`/`updating`: la normalizzazione di
 * UgcObserver e il calcolo del cammino in UgcObserver::created() trovano già la geometria pulita.
 * Registrato dal modello e non dentro UgcObserver, perché un consumer (camminiditalia) registra
 * una seconda volta una sottoclasse di UgcObserver.
 */
class UgcTrackGeometryCleanupObserver
{
    public function saving(UgcTrack $track): void
    {
        if ($track->exists && ! $track->isDirty('geometry') && ! $track->isDirty('properties')) {
            return;
        }

        $expression = UgcTrackCleanupService::make()->geometryExpressionFor($track);

        if ($expression !== null) {
            $track->geometry = $expression;
        }
    }
}
