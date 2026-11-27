<?php

namespace Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src;

use Laravel\Nova\Card;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\PostHog\AnalyticsService;

/**
 * La mappa di un layer, con il percorso e le posizioni recenti delle persone, in una card Nova
 * (oc:9040). Con `LayerMapCard::global()` la mappa di tutti i layer: i percorsi semplificati di
 * tutti i layer e le persone vicine a uno di essi.
 *
 * Si può mettere dove un Field non va, per esempio in una Dashboard. I percorsi si scaricano una
 * volta; le posizioni arrivano da un endpoint a sé (`nova-vendor/layer-analytics/…/live-positions`,
 * protetto per ruolo) e il componente le riscarica con l'intervallo della loro cache
 * (`AnalyticsService::LIVE_POSITIONS_CACHE_SECONDS`). Senza PostHog non le chiede affatto. In
 * modalità layer il percorso arriva da `…/{layer}/routes`, cioè il GeoJSON del layer senza
 * posizioni, altrimenti i pallini comparirebbero due volte.
 *
 * La card non conosce i ruoli: chi può leggere cosa lo decidono gli endpoint.
 *
 * Il componente Vue è nel bundle del Field (resources/js/field.js), così la card non ha una
 * build propria.
 */
class LayerMapCard extends Card
{
    public $component = 'layer-map-card';

    public $width = 'full';

    /**
     * Box sotto la mappa con il pallino delle persone, quante sono e l'ora dell'ultimo
     * aggiornamento: spento di default, lo accende withLiveLegend() (oc:9040).
     */
    private bool $showLiveLegend = false;

    /**
     * @param  Layer|null  $layer  null = tutti i layer (usare global())
     */
    public function __construct(private readonly ?Layer $layer = null)
    {
        parent::__construct();
    }

    /**
     * Sotto la mappa un box con il pallino delle persone (lo stesso della mappa, quindi fa da
     * legenda), quante sono negli ultimi 30 minuti e l'ora dell'ultimo aggiornamento. Il numero lo
     * conta il componente sulle posizioni che riceve, quindi coincide sempre con i pallini. Testi
     * dalle traduzioni, colori da variabili CSS (vedi LayerMapCard.vue).
     */
    public function withLiveLegend(bool $show = true): static
    {
        $this->showLiveLegend = $show;

        return $this;
    }

    /**
     * La mappa di tutti i layer.
     */
    public static function global(): static
    {
        return new static(null);
    }

    public function jsonSerialize(): array
    {
        $livePositions = app(AnalyticsService::class)->isConfigured();
        $prefix = '/nova-vendor/layer-analytics';
        $scope = $this->layer === null ? '' : '/'.$this->layer->id;

        $card = [
            // Una sola condizione per legenda e numero: accesi dallo shard e PostHog configurato.
            'show_live_legend' => $this->showLiveLegend && $livePositions,
            // Testi del box sotto la mappa (oc:9040). Neutri nel package: uno shard li cambia con
            // le sue traduzioni. `:time` lo sostituisce il componente con l'ora dell'aggiornamento.
            'live_count_texts' => [
                'one' => __('user on the map'),
                'other' => __('users on the map'),
                'window' => __('in the last 30 minutes'),
                'updated' => __('Updated at :time'),
            ],
            // Senza PostHog niente polling: l'endpoint risponderebbe sempre vuoto.
            'live_positions_url' => $livePositions ? "{$prefix}{$scope}/live-positions" : null,
            'live_positions_refresh_seconds' => AnalyticsService::LIVE_POSITIONS_CACHE_SECONDS,
        ];

        if ($this->layer === null) {
            return array_merge(parent::jsonSerialize(), $card, [
                'title' => __('All routes'),
                'geojson_url' => "{$prefix}/all-routes",
                'resource_name' => null,
                'resource_id' => null,
            ]);
        }

        return array_merge(parent::jsonSerialize(), $card, [
            'title' => $this->layer->getStringName(),
            'geojson_url' => "{$prefix}{$scope}/routes",
            'resource_name' => 'layers',
            'resource_id' => $this->layer->id,
        ]);
    }
}
