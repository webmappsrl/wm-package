<?php

namespace Wm\WmPackage\TrailRegistry\Services;

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Models\TrailStatusEvent;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * Il blocco `properties.walkability` del dettaglio pubblico di un sentiero
 * (oc:8989): data, autore e nota dell'ultima registrazione.
 *
 * Lo **stato** non sta qui: e' un termine di tassonomia e esce da se' sotto
 * `properties.taxonomies`, con il nome tradotto e l'icona. Qui ci sono i dati
 * che tassonomia non sono, in un blocco solo invece che in tre chiavi sparse
 * come su osm2cai — dove la stessa informazione esce in cinque punti con tre
 * chiavi di primo livello e un quarto nome nei geojson di aree e settori, ed e'
 * cosi' che due fonti finiscono a contendersi lo stesso campo.
 *
 * `last_update` conserva il nome che il dato ha nella fonte, cosi' chi
 * confronta i due sistemi ritrova la stessa parola dove la cerca.
 *
 * **L'autore esce come id, non come nome**: e' un JSON pubblico, e il nome di
 * una persona che lavora per l'ente, accanto a un giudizio sul territorio, una
 * volta uscito non si ritira da dove e' stato copiato. Il nome si vede in Nova,
 * dove si legge dalla relazione.
 *
 * La cronologia completa non esce: resta nel backoffice.
 */
class WalkabilityDetailService
{
    /**
     * @return array{last_update: string, user: int|null, description: string|null}|null
     *                                                                                   `null` se il sentiero non ha mai avuto una registrazione: la chiave
     *                                                                                   non compare affatto, invece di comparire vuota.
     */
    public function forTrack(EcTrack $track): ?array
    {
        // A dominio spento il blocco non esce, anche se in tabella ci fossero
        // eventi di un'attivazione precedente: il contributo e' additivo, e uno
        // shard che non usa il Catasto deve vedere il dettaglio com'era.
        if (! config('wm-package.features.trail_registry.enabled')) {
            return null;
        }

        $family = $this->familyId();

        if ($family === null) {
            return null;
        }

        /** @var TrailStatusEvent|null $last */
        $last = TrailRegistryClasses::statusEvent()::query()
            ->where('ec_track_id', $track->getKey())
            ->where('taxonomy_id', $family)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($last === null) {
            return null;
        }

        return [
            'last_update' => $last->created_at->toIso8601String(),
            'user' => $last->user_id,
            'description' => $last->description,
        ];
    }

    private function familyId(): ?int
    {
        /** @var string $identifier */
        $identifier = config('wm-package.features.trail_registry.walkability_taxonomy', 'walkability-status');

        $id = \Wm\WmPackage\Models\TaxonomyTerm::query()
            ->where('identifier', $identifier)
            ->whereNull('parent_id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
