<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Cards\ApiLinksCard;

use Wm\WmPackage\Models\FeatureCollection;

class FeatureCollectionApiLinksCard extends ApiLinksCard
{
    public function __construct(FeatureCollection $featureCollection)
    {
        parent::__construct([]);

        $url = $featureCollection->getUrl();

        if ($url) {
            $this->addLink('GeoJSON', $url);
        }
    }
}
