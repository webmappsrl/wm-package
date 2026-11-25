<?php

namespace Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src;

use Laravel\Nova\Card;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\PostHog\AnalyticsService;

/**
 * La mappa di un layer, con il percorso e le posizioni recenti delle persone, in una card Nova
 * (oc:9040).
 *
 * Usa lo stesso GeoJSON del Field FeatureCollectionMap nella detail view del Layer, ma si può
 * mettere dove un Field non va, per esempio in una Dashboard. A differenza del Field non si
 * aggiorna da sola: le posizioni sono quelle del momento in cui si apre la pagina (lato server
 * restano comunque in cache 90 secondi).
 *
 * Il componente Vue è nel bundle del Field (resources/js/field.js), così la card non ha una
 * build propria.
 */
class LayerMapCard extends Card
{
    public $component = 'layer-map-card';

    public $width = 'full';

    public function __construct(private readonly Layer $layer)
    {
        parent::__construct();
    }

    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'title' => $this->layer->getStringName(),
            'geojson_url' => '/nova-vendor/feature-collection-map/layers/'.$this->layer->id,
            'resource_name' => 'layers',
            'resource_id' => $this->layer->id,
            'show_live_user_positions' => app(AnalyticsService::class)->isConfigured(),
            'legend_label' => __('User position (last 30 minutes)'),
        ]);
    }
}
