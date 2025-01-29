<?php

namespace Wm\WmPackage\Models;

use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Observers\OverlayLayerObserver;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class OverlayLayer extends GeometryModel
{
    use HasTranslations;
    /**
     * The attributes translatable
     *
     * @var array
     */
    public $translatable = ['label'];

    protected static function boot()
    {
        parent::boot();
        App::observe(OverlayLayerObserver::class);
    }

    /**
     * Define the relationship with the App model
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function layers(): MorphToMany
    {
        return $this->morphToMany(Layer::class, 'layerable');
    }
}
