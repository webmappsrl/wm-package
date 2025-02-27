<?php

namespace Wm\WmPackage\Models;

use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Log;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Observers\LayerObserver;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Traits\TaxonomyAbleModel;

class Layer extends Model
{
    use HasFactory, HasTranslations, TaxonomyAbleModel;
    // protected $fillable = ['rank'];

    protected static function boot()
    {
        parent::boot();
        Layer::observe(LayerObserver::class);
    }

    public array $translatable = ['title', 'subtitle', 'description', 'track_type'];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['query_string'];

    public function app()
    {
        return $this->belongsTo(App::class);
    }

    public function associatedApps()
    {
        return $this->belongsToMany(App::class, 'layer_associated_app');
    }

    public function overlayLayers()
    {
        return $this->morphMany(OverlayLayer::class, 'layerable', (new OverlayLayer())->getMorphClass());
    }

    public function ecTracks(): MorphMany
    {
        return $this->morphMany(EcTrack::class, 'layerable', (new EcTrack)->getMorphClass());
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
