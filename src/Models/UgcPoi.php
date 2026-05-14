<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Wm\WmPackage\Models\Abstracts\Point;
use Wm\WmPackage\Models\Interfaces\UserOwnedModelInterface;
use Wm\WmPackage\Observers\UgcObserver;
use Wm\WmPackage\Traits\OwnedByUserModel;
use Wm\WmPackage\Traits\TaxonomyAbleModel;
use Wm\WmPackage\Traits\TaxonomyWhereAbleModel;

/**
 * Class UgcPoi
 *
 *
 * @property int    id
 * @property string app_id
 * @property string relative_url
 * @property string geometry
 * @property string name
 * @property string description
 * @property string raw_data
 * @property mixed  ugc_media
 */
class UgcPoi extends Point implements UserOwnedModelInterface
{
    use OwnedByUserModel, TaxonomyAbleModel, TaxonomyWhereAbleModel;

    /**
     * @var mixed|string
     */
    protected $fillable = [
        'user_id',
        'app_id',
        'name',
        'geometry',
        'properties',
        'created_by',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    protected static function booted()
    {
        UgcPoi::observe(UgcObserver::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
