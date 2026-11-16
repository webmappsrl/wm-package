<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Illuminate\Support\Carbon;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCodeEvent;
use Wm\WmPackage\TrailRegistry\Models\TrailStatusEvent;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * La storia di un sentiero letta tutta insieme: i cambi di stato e gli eventi
 * dei codici catastali che quel sentiero ha avuto, in un'unica sequenza
 * ordinata per data decrescente (oc:8989).
 *
 * **Perche' due tabelle e non una.** Le due storie hanno soggetti diversi: un
 * evento di codice parla di un codice, che puo' esistere senza sentiero — un
 * numero riservato a un'istanza non ancora approvata ha gia' la sua storia e
 * non e' la storia di nessuna traccia. Hanno anche vocabolari diversi: gli
 * stati di un codice sono un enum chiuso, garantito da un `CHECK` in
 * database, mentre gli stati di un sentiero sono termini di tassonomia con
 * chiavi esterne vere verso `taxonomy_terms`. Una tabella sola o perderebbe
 * uno dei due vincoli, o terrebbe lo stato in una colonna di testo senza
 * nessuno dei due. Si uniscono qui, in lettura, dove costa una query in piu'
 * e non un vincolo in meno.
 *
 * **La storia nasce incompleta nel caso normale, ed e' noto.** La riga
 * dell'assegnazione del numero manca quando e' l'import a riconoscere il
 * codice: `TrailRegistryService::writeExistingCodeRow()` crea il codice gia'
 * assegnato senza passare da `recordEvent()`, e su forestas quello e' il
 * percorso della quasi totalita' dei sentieri. La timeline comincia quindi
 * dalla validazione e non dal numero. E' un follow-up annotato nell'overview
 * del ticket, non un difetto di questo renderer: qui si mostra tutto cio' che
 * e' stato registrato.
 */
class TrailHistoryRenderer
{
    /** Un cambio di stato del sentiero: la riga parla di termini di tassonomia. */
    public const KIND_STATUS = 'status';

    /** Un passaggio di stato di un codice catastale del sentiero. */
    public const KIND_CODE = 'code';

    /**
     * Le righe della storia, dalla piu' recente alla piu' vecchia.
     *
     * @return array<int, array{at: Carbon, kind: string, subject: string, from: string|null, to: string, who: string, note: string|null}>
     */
    public static function rowsFor(EcTrack $track): array
    {
        $rows = array_merge(
            self::statusRows($track),
            self::codeRows($track),
        );

        // `usort` e' stabile da PHP 8.0: due eventi con lo stesso istante
        // restano nell'ordine in cui sono stati letti, invece di scambiarsi di
        // posto a ogni chiamata.
        usort($rows, fn (array $a, array $b) => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return $rows;
    }

    /**
     * @return array<int, array{at: Carbon, kind: string, subject: string, from: string|null, to: string, who: string, note: string|null}>
     */
    private static function statusRows(EcTrack $track): array
    {
        /** @var class-string<TrailStatusEvent> $statusEvent */
        $statusEvent = TrailRegistryClasses::statusEvent();

        return $statusEvent::query()
            ->where('ec_track_id', $track->getKey())
            ->with(['taxonomy', 'fromTerm', 'toTerm', 'user'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TrailStatusEvent $event) => [
                'at' => $event->created_at,
                'kind' => self::KIND_STATUS,
                'subject' => (string) $event->taxonomy->name,
                'from' => $event->fromTerm?->name,
                'to' => (string) $event->toTerm->name,
                // Dove la storia dei codici scrive un trattino per l'utente
                // assente, qui si scrive la provenienza: gli eventi senza
                // persona sono la maggioranza. Si guarda `user_id` e non la
                // relazione perche' e' la colonna a dire se un autore c'e'.
                'who' => $event->user_id !== null ? (string) $event->user->name : $event->source->label(),
                'note' => $event->description,
            ])
            ->all();
    }

    /**
     * Gli eventi di **tutti** i codici che quel sentiero ha avuto, non solo di
     * quello attivo: un numero si libera e se ne assegna un altro, e la riga
     * liberata resta in tabella con il suo `ec_track_id` perche' l'indice
     * unico dei codici e' parziale.
     *
     * Il confronto con l'id del sentiero esclude da se' i codici senza
     * sentiero: `ec_track_id` e' nullable — un codice riservato non ne ha
     * ancora uno — e quelle righe non sono la storia di nessuna traccia.
     *
     * @return array<int, array{at: Carbon, kind: string, subject: string, from: string|null, to: string, who: string, note: string|null}>
     */
    private static function codeRows(EcTrack $track): array
    {
        /** @var class-string<TrailRegistryCode> $code */
        $code = TrailRegistryClasses::code();

        $rows = [];

        foreach ($code::query()->where('ec_track_id', $track->getKey())->with(['events.user'])->get() as $trailCode) {
            foreach ($trailCode->events as $event) {
                /** @var TrailRegistryCodeEvent $event */
                $rows[] = [
                    'at' => $event->created_at,
                    'kind' => self::KIND_CODE,
                    'subject' => $trailCode->code,
                    'from' => $event->from_status?->value,
                    'to' => $event->to_status->value,
                    'who' => $event->user_id !== null ? (string) $event->user->name : '—',
                    'note' => $event->reason,
                ];
            }
        }

        return $rows;
    }

    /**
     * La stessa sequenza come HTML di sola lettura per il detail, nella forma
     * di `CodeHistoryRenderer`: nessuna Resource dedicata e nessun HasMany.
     */
    public static function render(EcTrack $track): string
    {
        $rows = array_map(fn (array $row) => sprintf(
            '<tr><td style="padding:4px 12px 4px 0">%s</td><td style="padding:4px 12px 4px 0">%s</td>'
            .'<td style="padding:4px 12px 4px 0">%s &rarr; %s</td><td style="padding:4px 12px 4px 0">%s</td>'
            .'<td style="padding:4px 0">%s</td></tr>',
            e($row['at']->format('d/m/Y H:i')),
            e($row['subject']),
            e($row['from'] ?? '—'),
            e($row['to']),
            e($row['who']),
            e($row['note'] ?? '—'),
        ), self::rowsFor($track));

        if ($rows === []) {
            return '<p>Nessun evento registrato.</p>';
        }

        return '<table style="width:100%;font-size:0.875rem">'
            .'<thead><tr><th align="left">Quando</th><th align="left">Cosa</th>'
            .'<th align="left">Passaggio</th><th align="left">Chi</th>'
            .'<th align="left">Nota</th></tr></thead>'
            .'<tbody>'.implode('', $rows).'</tbody></table>';
    }
}
