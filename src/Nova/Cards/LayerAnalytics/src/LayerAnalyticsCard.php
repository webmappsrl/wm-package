<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Cards\LayerAnalytics;

use Carbon\Carbon;
use Laravel\Nova\Card;
use Wm\WmPackage\Models\Layer;

class LayerAnalyticsCard extends Card
{
    public $component = 'layer-analytics-card';

    public $width = 'full';

    public $onlyOnDetail = true;

    private ?int $layerId;

    private ?string $trackingSince;

    public function __construct(Layer $layer)
    {
        parent::__construct();
        $this->layerId = $layer->id ?? null;
        $this->trackingSince = $layer->created_at
            ? Carbon::parse($layer->created_at)->format('Y-m-d')
            : '2026-01-01';
    }

    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'endpoint' => '/nova-vendor/layer-analytics/'.$this->layerId,
            'layer_id' => $this->layerId,
            'tracking_since' => $this->trackingSince,
        ]);
    }
}
