<?php

namespace Wm\WmPackage\Models;

use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Observers\LayerObserver;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Traits\FeatureImageAbleModel;
use Wm\WmPackage\Traits\TaxonomyAbleModel;

class Layer extends Model
{
    use FeatureImageAbleModel, HasFactory, TaxonomyAbleModel;
    // protected $fillable = ['rank'];

    protected static function boot()
    {
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
        return $this->morphedByMany(App::class, 'layerable', 'app_layer', 'layer_id', 'layerable_id');
    }

    public function overlayLayers()
    {
        return $this->morphToMany(OverlayLayer::class, 'layerable');
    }

    public function ecTracks(): BelongsToMany
    {
        return $this->belongsToMany(EcTrack::class, 'ec_track_layer');
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
}
