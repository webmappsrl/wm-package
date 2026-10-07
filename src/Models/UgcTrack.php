<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Wm\WmPackage\Models\Abstracts\MultiLineString;
use Wm\WmPackage\Models\Interfaces\UserOwnedModelInterface;
use Wm\WmPackage\Observers\UgcObserver;
use Wm\WmPackage\Observers\UgcTrackGeometryCleanupObserver;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;
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

    /** Colore e tratteggio dei tratti ricostruiti sulla mappa (oc:8719), usati anche dalla legenda Nova. */
    public const RECONSTRUCTED_SEGMENT_COLOR = 'rgba(234, 88, 12, 1)';

    public const RECONSTRUCTED_SEGMENT_DASH = [8, 8];

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
        UgcTrack::observe(UgcTrackGeometryCleanupObserver::class);
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

    /**
     * Mappa Nova: oltre alla geometria, i tratti ricostruiti al posto dei punti GPS scartati
     * (oc:8719), tratteggiati. I punti scartati non si disegnano: sono a chilometri dalla traccia
     * e allargherebbero la mappa.
     */
    public function getFeatureCollectionMap(): array
    {
        $collection = parent::getFeatureCollectionMap();
        $cleanup = UgcTrackCleanupService::make();
        $locations = $cleanup->locationsOf($this);

        if ($locations === null) {
            return $collection;
        }

        foreach ($cleanup->gaps($locations) as $gap) {
            $collection['features'][] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [
                        [(float) $gap['from']['longitude'], (float) $gap['from']['latitude']],
                        [(float) $gap['to']['longitude'], (float) $gap['to']['latitude']],
                    ],
                ],
                'properties' => [
                    'strokeColor' => self::RECONSTRUCTED_SEGMENT_COLOR,
                    'strokeWidth' => 4,
                    'strokeDash' => self::RECONSTRUCTED_SEGMENT_DASH,
                    'tooltip' => __('Reconstructed segment: :discarded points discarded in :minutes min (max accuracy :accuracy m)', [
                        'discarded' => $gap['discarded'],
                        'minutes' => (int) ceil($gap['seconds'] / 60),
                        'accuracy' => (int) round($gap['max_accuracy']),
                    ]),
                ],
            ];
        }

        return $collection;
    }
}
