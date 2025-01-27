<?php

namespace Wm\WmPackage\Traits;

use Wm\WmPackage\Models\TaxonomyWhen;
use Wm\WmPackage\Models\TaxonomyTheme;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Models\TaxonomyTarget;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Models\TaxonomyActivity;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait TaxonomyAbleModel
{

    public function taxonomyWheres(): MorphToMany
    {
        return $this->morphToMany(TaxonomyWhere::class, 'taxonomy_whereable');
    }

    public function taxonomyWhens(): MorphToMany
    {
        return $this->morphToMany(TaxonomyWhen::class, 'taxonomy_whenable');
    }

    public function taxonomyTargets(): MorphToMany
    {
        return $this->morphToMany(TaxonomyTarget::class, 'taxonomy_targetable');
    }

    public function taxonomyThemes(): MorphToMany
    {
        return $this->morphToMany(TaxonomyTheme::class, 'taxonomy_themeable');
    }

    public function taxonomyActivities(): MorphToMany
    {
        return $this->morphToMany(TaxonomyActivity::class, 'taxonomy_activityable')
            ->withPivot(['duration_forward', 'duration_backward']);;
    }

    public function taxonomyPoiTypes(): MorphToMany
    {
        return $this->morphToMany(TaxonomyPoiType::class, 'taxonomy_poi_typeable');
    }
}
