<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Laravel\Nova\Nova;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

/**
 * Riempie le celle dell'elenco delle anomalie a partire dai dati della riga.
 *
 * **Colonne, non frasi.** Una prima stesura componeva per ogni riga un
 * paragrafo con fatto, azione e collegamento: alla prova dei dati veri si
 * leggeva male — l'azione e l'invito ad aprire la scheda erano identici su
 * ogni riga, e i due sentieri in gioco (quello in anomalia e l'altro) si
 * confondevano perche' comparivano nella stessa frase. Ora ogni informazione
 * ha la sua colonna, e cio' che vale per tutte le righe si dice una volta
 * sola nella spiegazione in cima.
 *
 * Le celle si compongono in lettura e non in scrittura di proposito: la
 * tabella tiene i DATI (`context`), non il testo. Cambiare come si presenta
 * un'anomalia e' modificare questa classe, e ogni riga gia' scritta si
 * adegua al primo caricamento — mentre un testo salvato resterebbe alla
 * versione vecchia finche' qualcuno non rilancia la normalizzazione.
 */
class AnomalyDetailRenderer
{
    /**
     * La descrizione di un'anomalia: una tabellina etichetta/valore, non un
     * paragrafo.
     *
     * Le righe cambiano con il tipo, ma la forma no: a sinistra il nome del
     * dato, a destra il dato. Cosi' si legge per colonna anche scorrendo
     * l'elenco, e i due sentieri in gioco — quello rimasto senza numero e
     * l'altro — non finiscono nella stessa frase, dove si confondevano.
     */
    public static function render(TrailRegistryAnomaly $anomaly): string
    {
        $context = $anomaly->context ?? [];

        $rows = match ($anomaly->type) {
            TrailRegistryAnomalyType::CodiceGiaAssegnato => [
                [__('Codice'), static::code((string) ($context['code'] ?? ''))],
                [__('Già assegnato a'), static::assignee($context)],
            ],
            TrailRegistryAnomalyType::SettoreDiscordante => [
                [__('Codice nei dati'), static::code((string) ($context['raw_code'] ?? ''))],
                [__('Codice dalla geometria'), static::code((string) ($context['proposed_code'] ?? ''))],
            ],
            TrailRegistryAnomalyType::GeometriaDuplicata => [
                [__('Stessa traccia di'), static::twins($context)],
            ],
            TrailRegistryAnomalyType::CodiceIlleggibile => [
                [__('Codice nei dati'), static::code((string) ($context['raw_code'] ?? ''))],
                [__('Primo libero nel settore'), static::code((string) ($context['proposed_code'] ?? ''))],
            ],
            TrailRegistryAnomalyType::FuoriDaOgniSettore => [
                [__('Codice nei dati'), static::code((string) ($context['raw_code'] ?? ''))],
            ],
            // Nova costruisce i campi anche su un record vuoto, per ricavare
            // le colonne dell'elenco prima di avere le righe.
            null => [],
        };

        return static::table($rows);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $rows
     */
    protected static function table(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        // La colonna delle etichette si stringe sul suo contenuto
        // (`width:1%` con `nowrap`, il modo consueto di dire «larga quanto
        // basta» in una tabella): le etichette sono le stesse per tutte le
        // righe di uno stesso tipo, quindi i valori restano incolonnati fra
        // loro senza il vuoto che lasciava una larghezza fissa, tarata
        // sull'etichetta piu' lunga di tutte.
        //
        // La tabella non si stira a tutta la cella, altrimenti i valori
        // verrebbero spinti a destra dalla larghezza della colonna.
        //
        // Stile in linea e non classi: le utility Tailwind con valore
        // arbitrario non esistono nel CSS compilato di Nova.
        $html = '<table class="text-sm" style="border-collapse:collapse">';

        foreach ($rows as [$label, $value]) {
            $html .= '<tr>'
                .'<td class="text-gray-500 dark:text-gray-400"'
                .' style="width:1%;white-space:nowrap;padding:1px 16px 1px 0;'
                .'vertical-align:top;text-align:left">'
                .e($label).'</td>'
                .'<td style="padding:1px 0;vertical-align:top;text-align:left">'.$value.'</td>'
                .'</tr>';
        }

        return $html.'</table>';
    }

    protected static function sourceLinkTitle(): string
    {
        $label = config('wm-package.features.trail_registry.source_label');

        return is_string($label) && trim($label) !== ''
            ? __('Apri su :platform', ['platform' => $label])
            : __('Apri la scheda sulla piattaforma di origine');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected static function assignee(array $context): string
    {
        $assignee = $context['assigned_to'] ?? null;

        return is_array($assignee) ? static::trackLink($assignee) : static::none();
    }

    /**
     * Tutti i gemelli, non solo il primo: sono sullo stesso piano, e sapere
     * che sono tre invece di due cambia cosa il gestore deve andare a
     * guardare.
     *
     * @param  array<string, mixed>  $context
     */
    protected static function twins(array $context): string
    {
        $twins = is_array($context['twins'] ?? null) ? $context['twins'] : [];

        $links = [];

        foreach ($twins as $twin) {
            if (is_array($twin)) {
                $links[] = static::trackLink($twin);
            }
        }

        return $links === [] ? static::none() : implode('<br>', $links);
    }

    protected static function none(): string
    {
        return '<span class="text-gray-400">&mdash;</span>';
    }

    protected static function code(string $code): string
    {
        return $code === ''
            ? '<em>'.e(__('assente')).'</em>'
            : '<strong class="font-mono">'.e($code).'</strong>';
    }

    /**
     * Un sentiero, con i suoi due indirizzi in un nome solo: il **nome** apre
     * la sua scheda qui dentro, l'**icona** accanto apre quella sulla
     * piattaforma di origine, dove la correzione si fa davvero.
     *
     * Due collegamenti e non due colonne: sono due modi di raggiungere lo
     * stesso sentiero, e separarli costringerebbe a leggere due volte lo
     * stesso nome.
     *
     * @param  array<string, mixed>  $track
     */
    public static function trackLink(array $track): string
    {
        $id = $track['id'] ?? null;
        $name = is_string($track['name'] ?? null) && trim($track['name']) !== ''
            ? $track['name']
            : ('#'.($id ?? '?'));

        $html = $id === null
            ? '<strong>'.e($name).'</strong>'
            // Anche il collegamento interno apre una scheda nuova: da questa
            // lista si spunta un caso dopo l'altro, e tornare indietro ogni
            // volta farebbe perdere filtro, pagina e punto in cui si era.
            : '<a href="'.e(static::internalUrl((int) $id)).'" target="_blank" rel="noopener noreferrer"'
                .' class="no-underline text-primary-500"><strong>'.e($name).'</strong></a>';

        $source = is_string($track['url'] ?? null) ? $track['url'] : null;

        if ($source !== null) {
            $html .= ' '.static::sourceIcon($source);
        }

        return $html;
    }

    /**
     * L'indirizzo della scheda del sentiero dentro il pannello.
     *
     * La chiave nell'URL e' quella che Nova ricava dal nome della Resource
     * (`EcTrack` -> `ec-tracks`), quindi stabile: non serve risalire dal
     * modello alla Resource per scoprirla. Resta configurabile perche' un
     * consumer puo' sovrascrivere `uriKey()`, e in quel caso il collegamento
     * porterebbe a una pagina inesistente.
     */
    protected static function internalUrl(int $ecTrackId): string
    {
        $uriKey = (string) config(
            'wm-package.features.trail_registry.nova_uri_keys.ec_track',
            'ec-tracks',
        );

        return rtrim(Nova::path(), '/').'/resources/'.$uriKey.'/'.$ecTrackId;
    }

    /**
     * L'icona che porta alla piattaforma di origine: una freccia che esce da
     * un riquadro, la convenzione per «questo apre altrove». Il titolo dice
     * dove, cosi' l'icona non resta da indovinare — e dice il NOME della
     * piattaforma quando il consumer lo ha configurato («Apri su Drupal»),
     * perche' a chi ci deve andare a lavorare quel nome dice piu' di una
     * perifrasi.
     */
    protected static function sourceIcon(string $url): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"'
            .' stroke-width="2" stroke="currentColor" class="inline-block"'
            .' style="width:1em;height:1em;vertical-align:-0.125em">'
            .'<path stroke-linecap="round" stroke-linejoin="round"'
            .' d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25'
            .' 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />'
            .'</svg>';

        return '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer"'
            .' class="no-underline text-gray-500 hover:text-primary-500"'
            .' title="'.e(static::sourceLinkTitle()).'">'.$svg.'</a>';
    }
}
