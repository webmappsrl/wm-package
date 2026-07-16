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

    private bool $isGlobal = false;

    public function __construct(?Layer $layer = null)
    {
        parent::__construct();
        $this->layerId = $layer->id ?? null;
        $this->trackingSince = $layer?->created_at
            ? Carbon::parse($layer->created_at)->format('Y-m-d')
            : '2026-01-01';
    }

    public static function global(): static
    {
        $card = new static;
        $card->isGlobal = true;
        $card->onlyOnDetail = false;

        return $card;
    }

    public function jsonSerialize(): array
    {
        $endpoint = $this->isGlobal
            ? '/nova-vendor/layer-analytics/global'
            : '/nova-vendor/layer-analytics/'.$this->layerId;

        return array_merge(parent::jsonSerialize(), [
            'endpoint' => $endpoint,
            'layer_id' => $this->layerId,
            'tracking_since' => $this->trackingSince,
            'mode' => $this->isGlobal ? 'global' : 'layer',
        ]);
    }
}
