<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Models\TaxonomyPoiTypeable;
use Wm\WmPackage\Services\AppIconsService;
use Wm\WmPackage\Services\Models\LayerService;

class TaxonomyPoiTypeablesObserver
{
    public function __construct(protected LayerService $layerService) {}

    public function created(TaxonomyPoiTypeable $taxonomyPoiTypeable)
    {
        $this->handleTaxonomyAssignment($taxonomyPoiTypeable, true);
    }

    public function deleted(TaxonomyPoiTypeable $taxonomyPoiTypeable)
    {
        $this->handleTaxonomyAssignment($taxonomyPoiTypeable, false);
    }

    private function handleTaxonomyAssignment(TaxonomyPoiTypeable $taxonomyPoiTypeable, bool $add)
    {
        $appIconsService = AppIconsService::make();
        $relatedTypeClass = $taxonomyPoiTypeable->taxonomy_poi_typeable_type;

        if (str_contains($relatedTypeClass, '\EcPoi')) {
            $relatedModel = $taxonomyPoiTypeable->model;
            if ($relatedModel && isset($relatedModel->app)) {
                $appId = $relatedModel->app->id;
                $appIconsService->writeIconsOnAws($appId);
                BuildAppPoisGeojsonJob::dispatch($appId);
            }
        }
    }
}
