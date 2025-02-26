<?php

namespace Wm\WmPackage\Traits;

use App\Nova\User;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcTrack;

trait EcTrait
{
    protected function ecNovaFields(NovaRequest $request): array
    {
        $isTrack = $this->isTrackResource($request);

        $relatedResource = $isTrack
            ? \Wm\WmPackage\Nova\EcPoi::class
            : \Wm\WmPackage\Nova\EcTrack::class;

        $relatedResourceLabel = $isTrack ? 'EcPois' : 'EcTracks';

        return [
            Number::make('Osm Id', 'osm_id'),
            BelongsToMany::make($relatedResourceLabel, $relatedResourceLabel, $relatedResource),
        ];
    }

    /**
     * Determine if the current resource is an EcTrack
     *
     * @param NovaRequest $request
     * @return bool
     */
    private function isTrackResource(NovaRequest $request): bool
    {
        if ($request->viaResource() !== null) {
            return $request->viaResource() === 'App\Nova\EcTrack';
        }

        return $request->model() instanceof EcTrack;
    }
}
