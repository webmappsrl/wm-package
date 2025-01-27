<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Observers\EcMediaObserver;

class EcMedia extends GeometryModel
{
    use HasFactory, HasTranslations;

    /**
     * @var array
     */
    protected $fillable = ['name', 'url', 'geometry', 'out_source_feature_id', 'description', 'excerpt'];

    public array $translatable = ['name', 'description', 'excerpt'];

    protected static function boot()
    {
        App::observe(EcMediaObserver::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function ecPois(): BelongsToMany
    {
        return $this->belongsToMany(EcPoi::class);
    }

    public function ecTracks(): BelongsToMany
    {
        return $this->belongsToMany(EcTrack::class);
    }

    public function layers(): BelongsToMany
    {
        return $this->belongsToMany(Layer::class);
    }

    public function taxonomyActivities(): MorphToMany
    {
        return $this->morphToMany(TaxonomyActivity::class, 'taxonomy_activityable');
    }

    public function taxonomyPoiTypes(): MorphToMany
    {
        return $this->morphToMany(TaxonomyPoiType::class, 'taxonomy_poi_typeable');
    }

    public function taxonomyTargets(): MorphToMany
    {
        return $this->morphToMany(TaxonomyTarget::class, 'taxonomy_targetable');
    }

    public function taxonomyThemes(): MorphToMany
    {
        return $this->morphToMany(TaxonomyTheme::class, 'taxonomy_themeable');
    }

    public function taxonomyWhens(): MorphToMany
    {
        return $this->morphToMany(TaxonomyWhen::class, 'taxonomy_whenable');
    }

    public function taxonomyWheres(): MorphToMany
    {
        return $this->morphToMany(TaxonomyWhere::class, 'taxonomy_whereable');
    }

    public function featureImageEcPois(): HasMany
    {
        return $this->hasMany(EcPoi::class, 'feature_image');
    }

    public function featureImageEcTracks(): HasMany
    {
        return $this->hasMany(EcTrack::class, 'feature_image');
    }

    public function featureImageLayers(): HasMany
    {
        return $this->hasMany(Layer::class, 'feature_image');
    }

    public function getPathAttribute()
    {
        return parse_url($this->url)['path'];
    }
}
