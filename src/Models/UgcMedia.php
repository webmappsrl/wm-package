<?php

namespace Wm\WmPackage\Models;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Wm\WmPackage\Models\Abstracts\Media;
use Wm\WmPackage\Observers\UgcMediaObserver;

/**
 * Class UgcMedia
 *
 *
 * @property int    id
 * @property string app_id
 * @property string relative_url
 * @property string geometry
 * @property string name
 * @property string description
 * @property string raw_data
 */
class UgcMedia extends Media
{
    use HasFactory;

    private $beforeCount = 0;

    protected $fillable = [
        'user_id',
        'app_id',
        'name',
        'description',
        'relative_url',
        'raw_data',
        'geometry',
    ];

    public $preventHoquSave = false;

    protected static function boot()
    {
        App::observe(UgcMediaObserver::class);
    }

    public function ugc_pois(): BelongsToMany
    {
        return $this->belongsToMany(UgcPoi::class);
    }

    public function ugc_tracks(): BelongsToMany
    {
        return $this->belongsToMany(UgcTrack::class);
    }

    public function taxonomy_wheres(): BelongsToMany
    {
        return $this->belongsToMany(TaxonomyWhere::class);
    }
}
