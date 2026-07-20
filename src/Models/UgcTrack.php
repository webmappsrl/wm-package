<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Wm\WmPackage\Models\Abstracts\MultiLineString;
use Wm\WmPackage\Models\Interfaces\UserOwnedModelInterface;
use Wm\WmPackage\Observers\UgcObserver;
use Wm\WmPackage\Traits\OwnedByUserModel;
use Wm\WmPackage\Traits\TaxonomyAbleModel;
use Wm\WmPackage\Traits\TaxonomyWhereAbleModel;

/**
 * Class UgcTrack
 *
 *
 * @property int    id
 * @property array sku
 * @property string relative_url
 * @property string geometry
 * @property string name
 * @property string description
 * @property string raw_data
 */
class UgcTrack extends MultiLineString implements UserOwnedModelInterface
{
    use OwnedByUserModel, TaxonomyAbleModel, TaxonomyWhereAbleModel;

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
        parent::booted();
        UgcTrack::observe(UgcObserver::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Persisted final Stories share image (oc:8183, third revision) — needed so the public
     * `GET /share/ugc-track/{uuid}` page (see ShareUgcTrackController) can serve it to OG
     * crawlers (WhatsApp/Facebook/Twitter) asynchronously, potentially long after the share
     * request that generated it. `singleFile()`: re-sharing the same track replaces the
     * previous snapshot image rather than accumulating one per share.
     *
     * Overrides (does not replace) the parent's `registerMediaCollections()` — GeometryModel
     * registers a generic `default` collection used elsewhere for UGC photos; that one is
     * kept as-is.
     */
    public function registerMediaCollections(): void
    {
        parent::registerMediaCollections();

        $this->addMediaCollection('share_image')->singleFile();
    }
}
