<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Nova\Cards\TrailRegistryInconsistenciesCard;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailInconsistencyTypeFilter;

/**
 * Le incongruenze catastali: casi in cui due informazioni che il catasto
 * pubblica sullo stesso sentiero non reggono insieme (oc:8989).
 *
 * **Perche' una Resource propria e non l'elenco delle anomalie.** Le righe
 * stanno nella stessa tabella, distinte dal `source`, ma le due famiglie si
 * lavorano in modo opposto: un'anomalia riguarda un dato che appartiene alla
 * piattaforma di origine, si corregge la' e sparisce al giro d'import
 * successivo; un'incongruenza si risolve qui dentro, inserendo il dato che
 * manca. La schermata delle anomalie dice in testa che «le correzioni non si
 * fanno da questa schermata»: mostrarci dentro le incongruenze darebbe a chi
 * legge l'istruzione esattamente sbagliata.
 *
 * Eredita tutto il resto — colonne, ordinamento, mappa, divieto di creare e
 * cancellare — perche' la tabella e' la stessa e cio' che cambia e' il
 * significato delle righe, non la loro forma.
 */
class TrailRegistryInconsistency extends TrailRegistryAnomaly
{
    /**
     * Chiave fissa, come per le altre Resource del dominio: anche una
     * sottoclasse dello shard resta raggiungibile con la stessa.
     */
    public static function uriKey()
    {
        return 'trail-registry-inconsistencies';
    }

    public static function label(): string
    {
        return __('Registry inconsistencies');
    }

    public static function singularLabel(): string
    {
        return __('Registry inconsistency');
    }

    public static function indexQuery(NovaRequest $request, $query)
    {
        $query->where('source', TrailRegistryInconsistenciesCard::SOURCE);

        if (empty($request->query('orderBy'))) {
            $query->getQuery()->orders = [];

            $query->orderBy('type')->orderByRaw('ec_track_id NULLS LAST');
        }

        return $query;
    }

    /** @return array<int, \Laravel\Nova\Filters\Filter> */
    public function filters(NovaRequest $request): array
    {
        return [
            new TrailInconsistencyTypeFilter,
        ];
    }

    /**
     * La spiegazione in cima: dice il contrario di quella delle anomalie,
     * perche' qui il dato che manca si inserisce, non si corregge altrove.
     */
    protected function noticeBody(): string
    {
        $intro = __('These rows are not errors: they are what the registry looks like seen as a whole. Two pieces of information it publishes about the same trail do not hold together, and the one that is missing is missing where it should be.');

        $howTo = __('They are resolved <strong>from here</strong>, not at the source: open the trail from its name and enter the data. The row disappears by itself as soon as it is there.');

        $firstDay = __('A high number at the start is not an alarm: it is the work list, and it gets shorter as the data is filled in.');

        return '<h3 class="text-lg font-bold mb-3">'.__('What these rows are').'</h3>'
            .'<p class="mb-3">'.$intro.'</p>'
            .'<p class="mb-3">'.$howTo.'</p>'
            .'<p class="text-sm">'.$firstDay.'</p>';
    }
}
