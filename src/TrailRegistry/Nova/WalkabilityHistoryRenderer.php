<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Illuminate\Database\Eloquent\Collection;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Models\TrailStatusEvent;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * La cronologia della percorribilita' di un sentiero come HTML di sola lettura
 * per il detail di Nova (oc:8989).
 *
 * Nasce perche' la fonte, su osm2cai, mostra la storia con un campo `Code` che
 * stampa il jsonb grezzo: qui le righe si leggono. Stessa forma di
 * `CodeHistoryRenderer`, da cui resta pero' distinto — le colonne sono diverse
 * (una nota testuale invece di una causa breve) e gli stati sono termini di
 * tassonomia, mostrati con il loro nome tradotto, non codici.
 *
 * **Dove `CodeHistoryRenderer` scrive un trattino per l'utente assente, qui si
 * scrive la provenienza**: `user_id` e' valorizzato solo per l'inserimento a
 * mano, e una colonna di trattini non direbbe nulla proprio nella maggior parte
 * delle righe.
 *
 * Mostra solo gli eventi della famiglia della percorribilita': la tabella
 * `trail_status_events` e' pensata per piu' classificazioni, e la storia unita
 * del sentiero e' un'altra cosa (`TrailHistoryRenderer`).
 */
class WalkabilityHistoryRenderer
{
    public static function render(EcTrack $track): string
    {
        $rows = self::rowsFor($track)->map(function ($event) {
            return sprintf(
                '<tr><td style="padding:4px 12px 4px 0">%s</td><td style="padding:4px 12px 4px 0">%s &rarr; %s</td><td style="padding:4px 12px 4px 0">%s</td><td style="padding:4px 0">%s</td></tr>',
                e($event->created_at->format('d/m/Y H:i')),
                e($event->fromTerm->name ?? '—'),
                e($event->toTerm->name ?? '—'),
                e($event->description ?? ''),
                e($event->user->name ?? $event->source->label()),
            );
        })->implode('');

        if ($rows === '') {
            return '<p>Nessun passaggio registrato.</p>';
        }

        return '<table style="width:100%;font-size:0.875rem">'
            .'<thead><tr><th align="left">Quando</th><th align="left">Passaggio</th><th align="left">Nota</th><th align="left">Chi</th></tr></thead>'
            ."<tbody>{$rows}</tbody></table>";
    }

    /**
     * Gli eventi di percorribilita' del sentiero, dal piu' recente.
     *
     * @return Collection<int, TrailStatusEvent>
     */
    protected static function rowsFor(EcTrack $track): Collection
    {
        $event = TrailRegistryClasses::statusEvent();

        $familyId = TaxonomyTerm::where('identifier', self::familyIdentifier())->value('id');

        if ($familyId === null) {
            return $event::query()->whereRaw('1 = 0')->get();
        }

        return $event::query()
            ->with(['fromTerm', 'toTerm', 'user'])
            ->where('ec_track_id', $track->id)
            ->where('taxonomy_id', $familyId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * L'identifier della famiglia e' configurabile: qui si legge, non si decide.
     */
    protected static function familyIdentifier(): string
    {
        return config('wm-package.features.trail_registry.walkability_taxonomy', 'walkability-status');
    }
}
