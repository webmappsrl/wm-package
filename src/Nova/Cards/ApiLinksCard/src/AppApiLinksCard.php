<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Cards\ApiLinksCard;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\StorageService;

class AppApiLinksCard extends ApiLinksCard
{
    public function __construct(App $app)
    {
        $storage = StorageService::make();

        parent::__construct([
            ['label' => 'config.json', 'url' => $storage->getAppConfigUrl($app->id)],
            ['label' => 'icons.json', 'url' => $storage->getAppIconsUrl($app->id)],
            ['label' => 'pois.geojson', 'url' => $storage->getAppPoisUrl($app->id)],
            ['label' => 'icons.json (global)', 'url' => $storage->getGlobalIconsUrl()],
        ]);
    }
}
