<?php

namespace Wm\WmPackage\Observers;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyPoiTypeable;
use Wm\WmPackage\Models\User;
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
        $iconName = $taxonomyPoiTypeable->poiType->icon;
        $iconExists = $appIconsService->existIcon($iconName);
        $shouldUpdate = $add ? ! $iconExists : $iconExists;

        if ($shouldUpdate && str_contains($relatedTypeClass, '\EcPoi')) {
            $relatedModel = $taxonomyPoiTypeable->model;
            if ($relatedModel && isset($relatedModel->user_id)) {
                $user = User::find($relatedModel->user_id);
                $apps = App::where('user_id', $user->id)->get();
                foreach ($apps as $app) {
                    $appIconsService->writeIconsOnAws($app->id, $iconName);
                }
            }
        }
    }
}
