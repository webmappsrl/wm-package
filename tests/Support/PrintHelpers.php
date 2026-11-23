<?php

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;

/*
 * Tracce per i test della stampa PDF (oc:8982), comuni ai file di tests/Feature/Print e tests/Unit/Print.
 */

/** Traccia non salvata, con id e App fissati: per i test che non toccano il database. */
function printUnsavedTrack(int $id = 4242, ?int $appId = null): EcTrack
{
    $track = new EcTrack;
    $track->id = $id;
    if ($appId !== null) {
        $track->app_id = $appId;
    }

    return $track;
}

/**
 * Traccia salvata senza eventi (nessun observer né job accodato), dell'App data e senza osmid.
 *
 * @param  array<string,mixed>  $attributes
 */
function printSavedTrack(App $app, array $attributes = []): EcTrack
{
    return EcTrack::factory()->createQuietly(array_merge(['app_id' => $app->id, 'osmid' => null], $attributes));
}
