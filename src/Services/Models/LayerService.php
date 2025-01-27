<?php

namespace Wm\WmPackage\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;

class LayerService extends BaseService
{
    public function getLayerMaxRank(Layer $layer)
    {
        return DB::select(DB::raw('SELECT max(rank) from layers'))[0]->max;
    }

    public function getTracks(Layer $layer, $collection = false)
    {
        $taxonomies = ['Themes', 'Activities', 'Wheres'];

        // Estrazione dei dati che possono essere elaborati fuori dal foreach
        $user_id = $layer->getLayerUserID();
        $associated_app_users = $layer->associatedApps()->pluck('user_id')->toArray();

        // Aggiungi l'utente corrente all'array degli utenti associati
        array_push($associated_app_users, $user_id);

        // Logga gli utenti associati
        Log::channel('layer')->info('*************getTracks*****************');
        Log::channel('layer')->info('id: '.$layer->id);
        Log::channel('layer')->info('layer: '.$layer->name);
        Log::channel('layer')->info('Utenti associati per il layer: ', ['associated_users' => $associated_app_users]);

        // Partiamo recuperando tutte le tracce
        $allEcTracks = collect();

        // Utilizza il metodo chunk per processare i dati in blocchi più piccoli
        EcTrack::whereIn('user_id', $associated_app_users)
            ->whereNotNull('geometry')  // Controlla che la geometria non sia null
            ->whereRaw('ST_Dimension(geometry) = 1')  // Assicura che la geometria sia di dimensione 1 (linea)
            ->orderBy('id')
            ->orderBy('name')
            ->chunk(1000, function ($chunk) use (&$allEcTracks) {
                $allEcTracks = $allEcTracks->merge($chunk);
                unset($chunk);  // Libera la memoria usata dal chunk attuale
                gc_collect_cycles();  // Forza la garbage collection
            });

        // Logga il numero di tracce iniziali
        Log::channel('layer')->info('Numero iniziale di tracce: '.$allEcTracks->count());

        // Per ogni tassonomia, applichiamo un filtro sulle tracce
        foreach ($taxonomies as $taxonomy) {
            $taxonomyField = 'taxonomy'.$taxonomy;

            Log::channel('layer')->info("Inizio processamento tassonomia: $taxonomyField");

            if ($layer->$taxonomyField->count() > 0) {
                // Variabile per accumulare i termini della tassonomia corrente
                $taxonomyTerms = $layer->$taxonomyField;

                // Filtra le tracce per mantenere solo quelle che sono associate ai termini della tassonomia corrente
                $allEcTracks = $allEcTracks->filter(function ($track) use ($taxonomyTerms, $taxonomyField) {
                    try {

                        // Verifica se la traccia ha la tassonomia corrente; se non ha tassonomia, la scarta
                        if ($track->$taxonomyField->isEmpty()) {
                            return false;
                        }

                        // Controlla se la traccia ha almeno un termine della tassonomia corrente
                        return $track->$taxonomyField->intersect($taxonomyTerms)->isNotEmpty();
                    } catch (Exception $e) {
                        Log::channel('layer')->error("Errore durante il filtraggio delle tracce per la tassonomia $taxonomyField: ".$e->getMessage());

                        return false;
                    }
                });

                // Logga il numero di tracce rimanenti dopo il filtro per questa tassonomia
                Log::channel('layer')->info("Tracce rimanenti dopo il filtro di $taxonomyField: ".$allEcTracks->count());

                // Se non ci sono più tracce comuni, restituisci subito un array vuoto
                if ($allEcTracks->isEmpty()) {
                    Log::channel('layer')->info("Nessuna traccia comune trovata dopo l'applicazione di $taxonomyField. Restituzione array vuoto.");

                    return [];
                }
            } else {
                Log::channel('layer')->info("Nessun termine disponibile per la tassonomia $taxonomyField.");
            }

            unset($taxonomyTerms);  // Libera la memoria usata per i termini della tassonomia
            gc_collect_cycles();  // Forza la garbage collection
        }

        // Se collection è true, ritorna direttamente tutte le tracce raccolte
        if ($collection) {
            Log::channel('layer')->info('Ritorno tutte le tracce come collezione. Totale tracce: '.$allEcTracks->count());

            return $allEcTracks;
        }

        // Popola l'array dei track IDs fuori dal loop
        $trackIds = $allEcTracks->pluck('id')->toArray();

        // Logga il numero finale di track IDs raccolti
        Log::channel('layer')->info('Numero totale di track IDs raccolti: '.count($trackIds));

        // Libera la memoria utilizzata dalla collezione di tracce
        unset($allEcTracks);
        gc_collect_cycles();  // Forza la garbage collection

        return $trackIds;
    }

    public function getPbfTracks(Layer $layer)
    {
        // Chiamata a getTracks per ottenere la collection delle tracce filtrate
        $allEcTracks = $layer->ecTracks;

        // Verifica che ci siano tracce disponibili
        if ($allEcTracks->isEmpty()) {
            Log::channel('layer')->info('Nessuna traccia trovata da getTracks.');

            return collect(); // Restituisci una collezione vuota
        }

        // Logga il numero di tracce filtrate dalla geometria e dalle tassonomie
        Log::channel('layer')->info('Numero di tracce finali filtrate da getTracks: '.$allEcTracks->count());

        // Restituisci tracce uniche in base all'ID
        return $allEcTracks->unique('id');
    }

    public function getLayerUserID(Layer $layer)
    {
        return DB::table('apps')->where('id', $layer->app_id)->select(['user_id'])->first()->user_id;
    }

    /**
     * Determine if the user is an administrator.
     *
     * @return bool
     */
    public function getQueryStringAttribute(Layer $layer)
    {
        $query_string = '';

        if ($layer->taxonomyThemes->count() > 0) {
            $query_string .= '&taxonomyThemes=';
            $identifiers = $layer->taxonomyThemes->pluck('identifier')->toArray();
            $query_string .= implode(',', $identifiers);
        }
        if ($layer->taxonomyWheres->count() > 0) {
            $query_string .= '&taxonomyWheres=';
            $identifiers = $layer->taxonomyWheres->pluck('identifier')->toArray();
            $query_string .= implode(',', $identifiers);
        }
        if ($layer->taxonomyActivities->count() > 0) {
            $query_string .= '&taxonomyActivities=';
            $identifiers = $layer->taxonomyActivities->pluck('identifier')->toArray();
            $query_string .= implode(',', $identifiers);
        }

        return $layer->attributes['query_string'] = $query_string;
    }

    /**
     * Returns a list of taxonomy IDs associated with the layer.
     *
     * @return array
     */
    public function getLayerTaxonomyIDs(Layer $layer)
    {
        $ids = [];

        if ($layer->taxonomyThemes->count() > 0) {
            $ids['themes'] = $layer->taxonomyThemes->pluck('id')->toArray();
        }
        if ($layer->taxonomyWheres->count() > 0) {
            $ids['wheres'] = $layer->taxonomyWheres->pluck('id')->toArray();
        }
        if ($layer->taxonomyActivities->count() > 0) {
            $ids['activities'] = $layer->taxonomyActivities->pluck('id')->toArray();
        }

        return $ids;
    }
}
