<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Jobs\FeatureCollection\GenerateFeatureCollectionJob;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Traits\HasPackageFactory;

class FeatureCollection extends Model
{
    use HasPackageFactory, HasTranslations;

    public array $translatable = ['label'];

    protected $fillable = [
        'app_id',
        'name',
        'label',
        'enabled',
        'mode',
        'external_url',
        'file_path',
        'generated_at',
        'default',
        'clickable',
        'fill_color',
        'stroke_color',
        'stroke_width',
        'icon',
        'configuration',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'default' => 'boolean',
        'clickable' => 'boolean',
        'configuration' => 'array',
        'generated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if ($model->default) {
                static::where('app_id', $model->app_id)
                    ->where('id', '!=', $model->id ?? 0)
                    ->update(['default' => false]);
            }
        });

        static::saved(function (self $model) {
            if ($model->mode === 'generated') {
                GenerateFeatureCollectionJob::dispatch($model->id);
            }

            if ($model->app_id && $model->enabled) {
                UpdateAppConfigJob::dispatch($model->app_id);
            }
        });
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function layers(): BelongsToMany
    {
        return $this->belongsToMany(Layer::class, 'feature_collection_layer');
    }

    public function getUrl(): ?string
    {
        if ($this->mode === 'external') {
            return $this->external_url;
        }

        if ($this->file_path) {
            return Storage::disk('wmfe')->url($this->file_path);
        }

        return null;
    }
}
