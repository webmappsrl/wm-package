<?php

namespace Wm\WmPackage\Models\Abstracts;

use Illuminate\Support\Facades\Cache;
use Wm\WmPackage\Traits\HasPackageFactory;

abstract class MultiLineString extends GeometryModel
{
    use HasPackageFactory;

    /**
     * Quanto dura il lock del ricalcolo DEM di un model (oc:8660): una
     * traccia che il servizio DEM non riesce a calcolare riprova al massimo
     * una volta in questo intervallo, non a ogni apertura del dettaglio.
     */
    public const DEM_LOCK_SECONDS = 3600;

    /**
     * Serve il DEM se la geometria e' valida e manca il dato: `dem_data`
     * vuoto, oppure quote tutte a zero, che e' la geometria come arriva da
     * un file senza quota prima che il nostro DEM la riempia.
     *
     * La validita' la giudica PostGIS, come faceva l'istanza del catasto
     * (oc:8571): una geometria rotta farebbe partire un job destinato a
     * fallire. La stessa query restituisce il GeoJSON su cui controllare le Z.
     */
    public function needsDem(): bool
    {
        $row = $this->getConnection()->selectOne(
            "SELECT ST_AsGeoJSON(geometry) AS geojson
             FROM {$this->getTable()}
             WHERE {$this->getKeyName()} = ?
               AND geometry IS NOT NULL
               AND ST_IsValid(geometry::geometry)
               AND NOT ST_IsEmpty(geometry::geometry)",
            [$this->getKey()],
        );

        if ($row === null) {
            return false;
        }

        if (empty($this->properties['dem_data'] ?? null)) {
            return true;
        }

        return $this->hasOnlyZeroElevations(json_decode($row->geojson, true) ?? []);
    }

    /**
     * Prende il lock del ricalcolo DEM di questo model. Vale anche per la
     * creazione: il dettaglio che Nova apre subito dopo trova il lock preso e
     * non accoda una seconda catena in parallelo alla prima (oc:8660).
     *
     * Il lock sta qui e non nei job: Bus::chain() non rispetta ShouldBeUnique,
     * perche' PendingChain::dispatch() passa dal Dispatcher e non da
     * PendingDispatch, dove il controllo vive.
     */
    public function acquireDemLock(): bool
    {
        return Cache::store('redis')->add(
            'dem-lock:'.$this->getTable().':'.$this->getKey(),
            true,
            self::DEM_LOCK_SECONDS,
        );
    }

    public function dispatchDemIfMissing(): void
    {
        if ($this->needsDem() && $this->acquireDemLock()) {
            $this->dispatchDem();
        }
    }

    /**
     * Cosa accodare per ricalcolare il DEM: lo decide ogni figlio. Qui non fa
     * nulla, e non e' abstract, perche' non tutti i figli lo usano ancora
     * (UgcTrack).
     */
    public function dispatchDem(): void {}

    /**
     * Una coordinata senza terza componente conta come quota zero.
     */
    protected function hasOnlyZeroElevations(array $geometry): bool
    {
        foreach ($geometry['coordinates'] ?? [] as $line) {
            foreach ($line as $point) {
                if ((float) ($point[2] ?? 0) !== 0.0) {
                    return false;
                }
            }
        }

        return true;
    }
}
