<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Jobs\UpdateUgcTrackDemStatsJob;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;
use Wm\WmPackage\Services\Models\UgcTrackStatsService;

/**
 * Ricostruisce la geometria di una UgcTrack da properties.locations, scartando i punti GPS
 * inutilizzabili (oc:8719).
 *
 * Su `saving`, che Laravel emette prima di `creating`/`updating`: la normalizzazione di
 * UgcObserver e il calcolo del cammino in UgcObserver::created() trovano già la geometria pulita.
 * Registrato dal modello e non dentro UgcObserver, perché un consumer (camminiditalia) registra
 * una seconda volta una sottoclasse di UgcObserver.
 *
 * oc:8742: tiene allineato properties.stats ai punti GPS. Si ricalcola solo se la traccia è nuova
 * o se cambiano i punti; negli altri casi resta lo stats del DB, così un cambio di layer non perde
 * il dislivello del DEM e uno stats mandato dal client non viene mai salvato.
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

        $this->syncStats($track);
    }

    /**
     * Accoda il calcolo DEM quando stats ha ancora tutte le chiavi DEM vuote: una traccia nuova,
     * con i punti cambiati, o il cui DEM era fallito (riprova al salvataggio successivo).
     */
    public function saved(UgcTrack $track): void
    {
        $stats = $track->properties['stats'] ?? null;
        if (! is_array($stats) || ! ($track->wasRecentlyCreated || $track->wasChanged('properties'))) {
            return;
        }

        foreach (UgcTrackStatsService::DEM_KEYS as $key) {
            if (($stats[$key] ?? null) !== null) {
                return;
            }
        }

        UpdateUgcTrackDemStatsJob::dispatch($track->id, (string) $stats['computed_at'])->afterCommit();
    }

    private function syncStats(UgcTrack $track): void
    {
        $properties = (array) $track->properties;
        $original = $track->exists ? ($track->getOriginal('properties') ?? []) : [];
        $locations = UgcTrackCleanupService::make()->locationsOf($track);

        if ($locations === null) {
            unset($properties['stats']);
        } elseif (! $track->exists || ($original['locations'] ?? null) != $properties['locations']) {
            // != voluto: lo stesso elenco decodificato due volte dal JSON può differire solo nel
            // tipo numerico (int/float) dei valori.
            $stats = UgcTrackStatsService::make()->localStats($locations);
            if ($stats === null) {
                unset($properties['stats']);
            } else {
                $properties['stats'] = $stats;
            }
        } elseif (isset($original['stats'])) {
            $properties['stats'] = $original['stats'];
        } else {
            unset($properties['stats']);
        }

        $track->properties = $properties;
    }
}
