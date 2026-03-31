<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Cards\ApiLinksCard;

use Wm\WmPackage\Models\Layer;

class LayerApiLinksCard extends ApiLinksCard
{
    public function __construct(Layer $layer)
    {
        parent::__construct([
            [
                'label' => 'Elasticsearch',
                'url' => url('/api/v2/elasticsearch')
                    .'?app=geohub_app_'.$layer->app_id
                    .'&layer='.$layer->id,
            ],
        ]);
    }
}
