<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Support\TaxonomyTermLabel;
use Wm\WmPackage\TrailRegistry\Models\TrailStatusEvent;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * Uno stato del sentiero nella detail view: il valore di adesso, quando e' stato
 * registrato, da chi, e con quale nota (oc:8989).
 *
 * **Perche' non basta il valore.** Un giudizio sul terreno invecchia: «percorribile»
 * detto due anni fa e «percorribile» detto ieri non sono la stessa informazione, e
 * chi apre la scheda deve vedere la differenza senza andare a cercarla nello storico.
 *
 * Funziona per qualsiasi famiglia di `trail_status_events`, che e' una tabella sola
 * per piu' classificazioni: la percorribilita' oggi, la validazione quando avra' il
 * suo punto di scrittura. Dove l'evento non c'e' — il valore e' stato messo prima che
 * la storia esistesse, o da un percorso che non la registra — si mostra comunque il
 * valore, e si dice che di quella registrazione non si sa altro.
 */
class TrailStatusRenderer
{
    /**
     * @param  string  $familyIdentifier  La famiglia di cui mostrare lo stato.
     * @param  string|null  $emptyLabel  Cosa scrivere quando il sentiero non ha nessun termine
     *                                   di quella famiglia.
     */
    public static function render(EcTrack $track, string $familyIdentifier, ?string $emptyLabel = null): string
    {
        $family = TaxonomyTerm::query()
            ->where('identifier', $familyIdentifier)
            ->whereNull('parent_id')
            ->first();

        if ($family === null) {
            return '<p>'.e($emptyLabel ?? __('Not provided')).'</p>';
        }

        $current = $track->taxonomyTerms()
            ->where('taxonomy_terms.parent_id', $family->id)
            ->orderBy('taxonomy_terms.id')
            ->first();

        if ($current === null) {
            return '<p>'.e($emptyLabel ?? __('Not provided')).'</p>';
        }

        $event = self::lastEvent($track, $family->id);

        // Le stesse quattro righe per ogni stato, anche dove l'evento non c'e': due blocchi
        // affiancati con un numero diverso di righe si leggono come due cose diverse, e non lo
        // sono. Dove il dato manca si scrive un trattino, che dice «non registrato» invece di
        // tacere.
        $rows = [
            // «Stato», non «Valore»: la riga si legge «Stato: Validato», «Stato: Non
            // percorribile» — il nome di cosa si sta guardando, non la parola astratta.
            [__('Status'), TaxonomyTermLabel::for($current)],
            [__('Entered by'), $event === null
                ? '—'
                : ($event->user_id !== null ? (string) $event->user?->name : $event->source->label())],
            [__('Detected on'), $event?->created_at->format('d/m/Y H:i') ?? '—'],
            [__('Note'), $event?->description !== null && $event?->description !== '' ? $event->description : '—'],
        ];

        $html = '';
        foreach ($rows as [$label, $value]) {
            $html .= sprintf(
                '<tr><td style="padding:2px 16px 2px 0;opacity:.7;white-space:nowrap">%s</td><td style="padding:2px 0">%s</td></tr>',
                e($label),
                e($value),
            );
        }

        return '<table style="font-size:0.875rem"><tbody>'.$html.'</tbody></table>';
    }

    private static function lastEvent(EcTrack $track, int $familyId): ?TrailStatusEvent
    {
        /** @var TrailStatusEvent|null $event */
        $event = TrailRegistryClasses::statusEvent()::query()
            ->with('user')
            ->where('ec_track_id', $track->getKey())
            ->where('taxonomy_id', $familyId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $event;
    }
}
