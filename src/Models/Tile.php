<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Observers\TileObserver;

class Tile extends Model
{
    use HasTranslations;

    protected static function booted()
    {
        parent::booted();
        static::observe(TileObserver::class);
    }

    protected $table = 'tiles';

    protected $fillable = [
        'attribution',
        'label',
        'icon',
        'server_xyz',
        'link',
    ];

    public array $translatable = [
        'label',
    ];

    public function apps()
    {
        return $this->belongsToMany(App::class, 'app_tile')
            ->withPivot('sort_order')
            ->withTimestamps();
    }
}

