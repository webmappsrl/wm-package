<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Observers\OverlayLayerObserver;

class OverlayLayer extends GeometryModel
{

    /**
     * The attributes translatable
     *
     * @var array
     */
    public $translatable = ['label'];

    protected static function boot()
    {
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
