<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Wm\WmPackage\Models\Abstracts\Point;
use Wm\WmPackage\Models\Interfaces\UserOwnedModelInterface;
use Wm\WmPackage\Traits\OwnedByUserModel;

/**
 * Class UgcMedia
 *
 * @property int    id
 * @property int    app_id
 * @property int    user_id
 * @property string name
 * @property string relative_url
 * @property string geometry
 * @property array  properties
 */
class UgcMedia extends Point implements UserOwnedModelInterface
{
    use OwnedByUserModel;

    protected $fillable = [
        'user_id',
        'app_id',
        'name',
        'geometry',
        'relative_url',
        'properties',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function ugcPois(): BelongsToMany
    {
        return $this->belongsToMany(UgcPoi::class, 'ugc_media_ugc_poi');
    }

    public function ugcTracks(): BelongsToMany
    {
        return $this->belongsToMany(UgcTrack::class, 'ugc_media_ugc_track');
    }
}
