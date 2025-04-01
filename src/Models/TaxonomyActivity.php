<?php

namespace Wm\WmPackage\Models;

use Wm\WmPackage\Models\Abstracts\Taxonomy;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class TaxonomyActivity extends Taxonomy
{
    protected function getRelationKey(): string
    {
        return 'activityable';
    }

    public function layers(): MorphToMany
    {
        return $this->morphedByMany(Layer::class, 'taxonomy_' . $this->getRelationKey())
            ->using(TaxonomyActivityable::class); //this is necessary to make events on pivot working
        //https://github.com/chelout/laravel-relationship-events/issues/16
    }
}
