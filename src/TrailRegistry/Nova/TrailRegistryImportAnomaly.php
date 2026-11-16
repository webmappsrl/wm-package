<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Nova\Cards\TrailRegistryInconsistenciesCard;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailAnomalyTypeFilter;

/**
 * Le anomalie che l'import del catasto lascia dietro di se': i sentieri rimasti
 * senza numero, e il motivo per cui non l'hanno ottenuto.
 *
 * E' la schermata storica del dominio, con la sua chiave `trail-registry-anomalies`. Il dato di
 * ogni riga appartiene alla piattaforma di origine: si corregge la', e il giro d'import
 * successivo fa sparire la riga da se'. E' quello che la spiegazione in cima dice a chi guarda,
 * ed e' il motivo per cui le incongruenze catastali — che si risolvono qui dentro — hanno una
 * Resource loro ({@see TrailRegistryInconsistency}) invece di comparire in questo elenco
 * (oc:8989).
 */
class TrailRegistryImportAnomaly extends TrailRegistryAnomaly
{
    /**
     * Fissa, non derivata dal nome della classe: coerente con TrailRegistryCode
     * e TrailApplication, cosi' anche una sottoclasse dello shard resta
     * raggiungibile con la stessa chiave. E' la chiave storica di questa
     * schermata: cambiarla romperebbe i link gia' in giro e la voce di menu
     * che gli shard dichiarano.
     */
    public static function uriKey()
    {
        return 'trail-registry-anomalies';
    }

    public static function label(): string
    {
        return __('Anomalies');
    }

    public static function singularLabel(): string
    {
        return __('Anomaly');
    }

    /**
     * Ordinamento predefinito: prima la provenienza (uno shard puo' avere le
     * sue accanto a quelle del catasto senza mischiarle), poi il tipo — le
     * anomalie dello stesso genere stanno insieme, ed e' cosi' che si
     * lavorano, una correzione per volta ripetuta su tutte le schede che ne
     * hanno bisogno — infine la traccia, con quelle senza traccia in coda:
     * non hanno un id su cui ordinare, e non sono comunque il caso comune.
     */
    public static function indexQuery(NovaRequest $request, $query)
    {
        // Le incongruenze catastali stanno nella stessa tabella ma non sono
        // anomalie: non si correggono alla fonte e non spariscono al giro
        // d'import successivo, quindi la spiegazione in cima a questa schermata
        // non vale per loro. Hanno una Resource propria (oc:8989).
        $query->where('source', '!=', TrailRegistryInconsistenciesCard::SOURCE);

        if (empty($request->query('orderBy'))) {
            $query->getQuery()->orders = [];

            $query->orderBy('source')->orderBy('type')->orderByRaw('ec_track_id NULLS LAST');
        }

        return $query;
    }

    protected function noticeBody(): string
    {
        $intro = __('These are the trails that <strong>did not get a number</strong>, and the reason why. Only codes with no doubt pending enter the code registry: everything still unresolved is here, and while it is here that trail has no number.');

        // L'azione sta qui e non su ogni riga: e' la stessa per tutte, e
        // ripeterla nella tabella toglieva spazio a cio' che invece cambia.
        $howTo = __('Corrections <strong>are not made from this screen</strong>: the data belongs to the source platform. Each trail name carries two links — the <strong>name</strong> opens its record here, the <strong>icon</strong> next to it the one on the source platform: correct it there, and the next import removes the row on its own, assigning the number if it has meanwhile become assignable.');

        $columns = __('The <strong>Trail</strong> column always shows the one left without a number; the <strong>Detail</strong> shows why, next to the other side of the problem — a trail when someone else already has that code or the track is shared, a code when the problem is in the data.');

        $rows = [
            [__('Code already assigned'), __('Another trail already has that code: one of the two must be corrected at the source.')],
            [__('Sector mismatch'), __('The sector written in the code is not the one the track actually falls in. Next to it, the code the platform would compose from the geometry.')],
            [__('Duplicate geometry'), __('Two or more trails with the exact same track. Until it is known which one is right, none of them gets a number.')],
            [__('Unreadable code'), __('No valid number can be derived from the code field. Next to it, the first free number in the sector: it is a hint, not a decision — the right number is the one on the signage in the field.')],
            [__('Outside any sector'), __('The geometry does not fall in any sector, so there is no prefix to compose the code with.')],
        ];

        $list = '';

        foreach ($rows as [$label, $text]) {
            $list .= '<li class="mb-2"><strong>'.$label.'</strong> — '.$text.'</li>';
        }

        return '<h3 class="text-lg font-bold mb-3">'.__('What these rows are').'</h3>'
            .'<p class="mb-3">'.$intro.'</p>'
            .'<p class="mb-3">'.$columns.'</p>'
            .'<ul class="list-disc list-inside mb-3">'.$list.'</ul>'
            .'<p class="text-sm">'.$howTo.'</p>';
    }

    public function filters(NovaRequest $request): array
    {
        return [
            new TrailAnomalyTypeFilter,
        ];
    }
}
