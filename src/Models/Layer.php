<?php

namespace Wm\WmPackage\Models;

use Chelout\RelationshipEvents\Concerns\HasMorphToManyEvents;
use Chelout\RelationshipEvents\Traits\HasRelationshipObservables;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Log;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Observers\LayerObserver;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Traits\HasPackageFactory;
use Wm\WmPackage\Traits\TaxonomyAbleModel;
use Wm\WmPackage\Traits\TaxonomyWhereAbleModel;

class Layer extends Model
{
    use HasMorphToManyEvents, HasPackageFactory, HasRelationshipObservables, HasTranslations, TaxonomyAbleModel, TaxonomyWhereAbleModel;

    protected static function boot()
    {
        parent::boot();
        Layer::observe(LayerObserver::class);
    }

    public array $translatable = ['name', 'properties->title', 'properties->subtitle', 'properties->description'];

    protected $casts = [
        'properties' => 'array',
        'configuration' => 'array',
    ];

    protected $fillable = [
        'name',
        'properties',
        'configuration',
        'app_id',
        'geometry',
        'feature_collection',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    // protected $appends = ['query_string'];

    public function appOwner()
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    public function associatedApps()
    {
        return $this->belongsToMany(App::class, 'layer_associated_app');
    }

    public function ecTracks(): MorphToMany
    {
        return $this->morphedByMany(EcTrack::class, 'layerable');
    }

    public function manualEcPois(): MorphToMany
    {
        return $this->morphedByMany(EcPoi::class, 'layerable');
    }

    public function taxonomyActivities(): MorphToMany
    {
        return $this->morphToMany(TaxonomyActivity::class, 'taxonomy_activityable')
            ->using(TaxonomyActivityable::class); // this is necessary to make events on pivot working
        // https://github.com/chelout/laravel-relationship-events/issues/16;
    }

    /**
     * Move to a model mutator
     * https://laravel.com/docs/11.x/eloquent-mutators#defining-a-mutator
     *
     * @param [type] $defaultBBOX
     * @return void
     */
    public function computeBB($defaultBBOX)
    {
        $bbox = GeometryComputationService::make()->getTracksBbox($this->ecTracks);
        try {
            $this->bbox = $bbox ?? $defaultBBOX;
            $this->save();
        } catch (Exception $e) {
            Log::channel('layer')->error('computeBB of layer with id: '.$this->id);
        }
    }

    /**
     * Determine if the user is an administrator.
     *
     * @return bool
     */
    public function getQueryStringAttribute()
    {
        $query_string = '';

        if ($this->taxonomyThemes->count() > 0) {
            $query_string .= '&taxonomyThemes=';
            $identifiers = $this->taxonomyThemes->pluck('identifier')->toArray();
            $query_string .= implode(',', $identifiers);
        }
        if ($this->taxonomyWheres->count() > 0) {
            $query_string .= '&taxonomyWheres=';
            $identifiers = $this->taxonomyWheres->pluck('identifier')->toArray();
            $query_string .= implode(',', $identifiers);
        }
        if ($this->taxonomyActivities->count() > 0) {
            $query_string .= '&taxonomyActivities=';
            $identifiers = $this->taxonomyActivities->pluck('identifier')->toArray();
            $query_string .= implode(',', $identifiers);
        }

        return $this->attributes['query_string'] = $query_string;
    }
}
