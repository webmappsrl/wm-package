<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/** UgcTrack con almeno un segmento corrispondente su una EcTrack del layer scelto (oc:8773). */
class UgcTrackLayerFilter extends Filter
{
    public function name()
    {
        return __('Layer crossed by the track');
    }

    public function apply(Request $request, $query, $value)
    {
        $morph = UgcTrackMatchingService::make()->ecTrackMorphClass();

        return $query->whereExists(function ($q) use ($morph, $value) {
            $q->selectRaw('1')
                ->from('ugc_track_ec_track_matches as m')
                ->join('layerables as lb', function ($join) use ($morph) {
                    $join->on('lb.layerable_id', '=', 'm.ec_track_id')->where('lb.layerable_type', $morph);
                })
                ->whereColumn('m.ugc_track_id', 'ugc_tracks.id')
                ->where('lb.layer_id', (int) $value);
        });
    }

    /** Propone solo i layer attraversati da almeno una UgcTrack (esiste una corrispondenza con una EcTrack del layer). */
    public function options(Request $request)
    {
        $morph = UgcTrackMatchingService::make()->ecTrackMorphClass();

        return Layer::query()
            ->whereExists(function ($q) use ($morph) {
                $q->selectRaw('1')
                    ->from('layerables as lb')
                    ->join('ugc_track_ec_track_matches as m', 'm.ec_track_id', '=', 'lb.layerable_id')
                    ->where('lb.layerable_type', $morph)
                    ->whereColumn('lb.layer_id', 'layers.id');
            })
            ->orderBy('id')->get()
            ->mapWithKeys(fn (Layer $layer) => [$layer->getStringName().' (#'.$layer->id.')' => $layer->id])
            ->all();
    }
}
