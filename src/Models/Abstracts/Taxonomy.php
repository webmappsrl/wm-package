<?php

namespace Wm\WmPackage\Models\Abstracts;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Observers\TaxonomyObserver;
use Wm\WmPackage\Traits\FeatureImageAbleModel;

abstract class Taxonomy extends GeometryModel
{
    use FeatureImageAbleModel, HasFactory, HasTranslations;

    public array $translatable = ['name', 'description', 'excerpt'];

    protected $fillable = [
        'name',
        'import_method',
        'identifier',
    ];

    protected $casts = ['name' => 'array'];

    abstract protected function getRelationKey(): string;

    protected static function boot()
    {
        parent::boot();
        App::observe(TaxonomyObserver::class);
    }

    public function ecTracks(): MorphToMany
    {
        return $this->morphedByMany(EcTrack::class, 'taxonomy_'.$this->getRelationKey());
    }

    public function layers(): MorphToMany
    {
        return $this->morphedByMany(Layer::class, 'taxonomy_'.$this->getRelationKey());
    }

    public function ecPois(): MorphToMany
    {
        return $this->morphedByMany(EcPoi::class, 'taxonomy_'.$this->getRelationKey());
    }
}
