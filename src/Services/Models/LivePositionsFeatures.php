<?php

namespace Wm\WmPackage\Services\Models;

use Illuminate\Support\Facades\Log;
use Laravel\Nova\Nova;
use Wm\WmPackage\Models\User;

/**
 * Le posizioni recenti delle persone come Feature GeoJSON per le mappe Nova: il marker live della
 * detail view del Layer e degli endpoint delle posizioni della dashboard (oc:9040).
 */
class LivePositionsFeatures
{
    /**
     * @param  list<array{lat: float, lng: float, user_id?: ?int}>  $positions
     * @param  int|null  $layerId  solo per i log; null = tutti i cammini
     * @return list<array<string, mixed>>
     */
    public function fromPositions(array $positions, ?int $layerId = null): array
    {
        // oc:8637: nominativo e link sul marker live dipendono da una config per shard
        // (wm-package.analytics_show_live_user_identity ← ANALYTICS_SHOW_LIVE_USER_IDENTITY, default false). Supera la
        // scelta di oc:8586, che li aveva fissati a false nel codice: allo scrum del 23/09/2026 si è
        // deciso di renderli opzionali per shard, senza interruttore in Nova.
        // Limite noto: l'identità mostrata è quella dichiarata dall'app nell'evento PostHog
        // `userMoved` (properties.user_id), non verificata dal backend.
        // FILTER_VALIDATE_BOOLEAN e non (bool): su un flag di privacy un valore ambiguo nel .env
        // ("off", "no") deve lasciare il marker anonimo, mentre (bool) "off" varrebbe true.
        $showLiveUserIdentity = filter_var(config('wm-package.analytics_show_live_user_identity'), FILTER_VALIDATE_BOOLEAN);

        if ($showLiveUserIdentity) {
            try {
                $userIds = array_values(array_unique(array_filter(array_column($positions, 'user_id'))));
                $usersById = $userIds === []
                    ? collect()
                    : User::whereIn('id', $userIds)->get(['id', 'name', 'surname'])->keyBy('id');
            } catch (\Throwable $e) {
                Log::error('LivePositionsFeatures::fromPositions(): user_id lookup failed', ['layer_id' => $layerId, 'error' => $e->getMessage()]);
                $usersById = collect();
            }
        } else {
            $usersById = collect();
        }

        $features = [];

        foreach ($positions as $position) {
            $user = $showLiveUserIdentity && isset($position['user_id']) ? $usersById->get($position['user_id']) : null;
            $nominativo = $user ? trim("{$user->name} {$user->surname}") : '';

            $properties = [
                'tooltip' => $nominativo !== '' ? $nominativo : __('User position (last 30 minutes)'),
                'pointFillColor' => 'rgba(34, 197, 94, 0.9)',
                'pointStrokeColor' => 'rgba(255, 255, 255, 1)',
                'pointStrokeWidth' => 3,
                'pointRadius' => 8,
                // Anelli concentrici (bullseye), non solo colore diverso: gli EcPoi sono un
                // cerchio pieno senza questa property — un utente daltonico o una stampa in
                // scala di grigi distingue comunque i due marker dal profilo (anelli vs pieno),
                // non solo dalla tinta. Nessuna modifica al componente Vue: checkpointRouteColors
                // è già supportato da FeatureCollectionMap.vue::getFeatureStyle() per un altro
                // caso d'uso (percorsi multi-tratta).
                'checkpointRouteColors' => ['rgba(255, 255, 255, 1)', 'rgba(34, 197, 94, 0.9)'],
            ];

            // Gated su $showLiveUserIdentity oltre che su $user: con il flag spento $user è sempre
            // null (vedi sopra), la doppia condizione è una difesa in più. Con il flag acceso il
            // link dipende solo da $user risolto, non dal nominativo: un utente senza nome e
            // cognome ha tooltip generico ma link alla scheda (oc:8637, come prima di oc:8586).
            if ($showLiveUserIdentity && $user) {
                $properties['link'] = url(trim(Nova::path(), '/').'/resources/users/'.$user->id);
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$position['lng'], $position['lat']],
                ],
                'properties' => $properties,
            ];
        }

        return $features;
    }
}
