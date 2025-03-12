<?php

namespace Wm\WmPackage\Models;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Observers\LayerObserver;
use Wm\WmPackage\Traits\HasPackageFactory;
use Wm\WmPackage\Traits\TaxonomyAbleModel;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Wm\WmPackage\Services\GeometryComputationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Layer extends Model
{
    use HasTranslations, TaxonomyAbleModel, HasPackageFactory;
    // protected $fillable = ['rank'];

    protected static function boot()
    {
        parent::boot();
        Layer::observe(LayerObserver::class);
    }

    public array $translatable = ['name'];
    protected $casts = [
        'properties' => 'array',
        'configuration' => 'array'
    ];


    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    //protected $appends = ['query_string'];

    public function appOwner()
    {
        return $this->belongsTo(App::class);
    }

    public function associatedApps()
    {
        return $this->belongsToMany(App::class, 'layer_associated_app');
    }

    public function manualEcTracks(): MorphToMany
    {
        return $this->morphedByMany(EcTrack::class, 'layerable');
    }

    public function manualEcPois(): MorphToMany
    {
        return $this->morphedByMany(EcPoi::class, 'layerable');
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
            Log::channel('layer')->error('computeBB of layer with id: ' . $this->id);
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
