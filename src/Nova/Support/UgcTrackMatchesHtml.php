<?php

namespace Wm\WmPackage\Nova\Support;

use Illuminate\Support\Facades\DB;
use Laravel\Nova\Nova;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\UgcTrackMatchingRun;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/**
 * Layer ed EcTrack attraversati da una UgcTrack (oc:8773), con i segmenti corrispondenti di ogni
 * EcTrack.
 * Una EcTrack in più layer compare sotto ciascuno; le EcTrack senza layer in un gruppo a parte;
 * le righe di layerables che puntano a layer cancellati sono ignorate.
 *
 * Accanto a ogni layer il valore aggregato: segmenti corrispondenti di tutte le EcTrack del layer,
 * sulla somma dei segmenti di TUTTE le EcTrack del layer, anche quelle che la traccia non tocca
 * (scelta del dev). Una EcTrack in più layer conta in ciascuno; il gruppo fuori da ogni layer non
 * ha aggregato.
 *
 * Se la corrispondenza manca, è fallita o è stata calcolata con parametri diversi da quelli attuali
 * della App, il dettaglio accoda il calcolo e chiede di ricaricare la pagina.
 */
class UgcTrackMatchesHtml
{
    public static function render(UgcTrack $track): string
    {
        // Stato e parametri letti una volta sola: servono al controllo, al dispatch e ai totali.
        $service = UgcTrackMatchingService::make();
        $run = $track->matchingRun()->first();
        $params = $service->params((int) $track->app_id);
        if (UgcTrack::runNeedsMatching($run, $params)) {
            $track->dispatchMatchingIfNeeded(needed: true);
            $html = '<p>'.e(__('Matching in progress: reload the page shortly')).'</p>';
            if ($run?->status === UgcTrackMatchingRun::STATUS_FAILED) {
                $html .= '<p>'.e(__('Matching failed')).': '.e((string) $run->error).'</p>';
            }

            return $html;
        }

        $rows = DB::table('ugc_track_ec_track_matches as m')
            ->leftJoin('layerables as lb', function ($join) use ($service) {
                $join->on('lb.layerable_id', '=', 'm.ec_track_id')
                    ->where('lb.layerable_type', $service->ecTrackMorphClass())
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('layers')->whereColumn('layers.id', 'lb.layer_id'));
            })
            ->where('m.ugc_track_id', $track->id)
            ->orderBy('lb.layer_id')->orderBy('m.ec_track_id')
            ->get(['lb.layer_id', 'm.ec_track_id', 'm.matched_segments', 'm.total_segments'])
            // una riga layerables duplicata non deve mostrare due volte la stessa EcTrack nel layer
            ->unique(fn ($r) => ($r->layer_id ?? 0).'-'.$r->ec_track_id)
            ->values();

        if ($rows->isEmpty()) {
            return '<p>'.e(__('The track does not cross any EcTrack')).'</p>';
        }

        $layers = Layer::query()->whereIn('id', $rows->pluck('layer_id')->filter()->unique())->get()->keyBy('id');
        $aggregates = self::layerAggregates($track, $rows, $layers->keys()->all(), $params);
        $ecClass = config('wm-package.ec_track_model', EcTrack::class);
        if (! class_exists($ecClass)) {
            $ecClass = EcTrack::class; // come in ecTrackMorphClass(): nel package il modello del consumer può non esistere
        }
        $ecTracks = $ecClass::query()->whereIn('id', $rows->pluck('ec_track_id')->unique())->get()->keyBy('id');

        $html = '';
        foreach ($rows->groupBy(fn ($r) => $r->layer_id ?? 0) as $layerId => $group) {
            $title = $layerId === 0
                ? e(__('EcTracks crossed outside any layer'))
                : self::link(\Wm\WmPackage\Nova\Layer::uriKey(), (int) $layerId, ($layers[$layerId] ?? null)?->getStringName() ?? (string) $layerId);
            $aggregate = $aggregates[$layerId] ?? null;
            $html .= '<p><strong>'.$title.'</strong>'
                .($aggregate === null ? '' : ' — '.self::fraction($aggregate['matched'], $aggregate['total']))
                .'</p><ul>';
            foreach ($group as $row) {
                $matched = count(json_decode($row->matched_segments, true) ?? []);
                $name = self::ecTrackName($ecTracks[$row->ec_track_id] ?? null, (int) $row->ec_track_id);
                $html .= '<li>'.self::link(\Wm\WmPackage\Nova\EcTrack::uriKey(), (int) $row->ec_track_id, $name)
                    .' — '.self::fraction($matched, (int) $row->total_segments).'</li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }

    /**
     * Valore aggregato per layer: due query in tutto, qualunque sia il numero di layer (le EcTrack
     * dei layer da layerables, poi i totali di segmenti di tutte con una sola query).
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows  righe di corrispondenza della traccia
     * @param  array<int, int>  $layerIds  layer esistenti fra quelli attraversati
     * @param  array{segment_length_meters:int, max_distance_meters:int}  $params  parametri attuali della App
     * @return array<int, array{matched:int, total:int}> layer_id => aggregato
     */
    private static function layerAggregates(UgcTrack $track, \Illuminate\Support\Collection $rows, array $layerIds, array $params): array
    {
        if ($layerIds === []) {
            return [];
        }
        $service = UgcTrackMatchingService::make();
        $ecByLayer = DB::table('layerables')
            ->whereIn('layer_id', $layerIds)
            ->where('layerable_type', $service->ecTrackMorphClass())
            ->get(['layer_id', 'layerable_id'])
            ->groupBy('layer_id')
            ->map(fn ($group) => $group->pluck('layerable_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $totals = $service->totalSegmentsForEcTracks($ecByLayer->flatten()->unique()->values()->all(), (int) $track->app_id, $params);

        $matchedByEc = [];
        foreach ($rows as $row) {
            $matchedByEc[(int) $row->ec_track_id] = count(json_decode($row->matched_segments, true) ?? []);
        }

        $aggregates = [];
        foreach ($ecByLayer as $layerId => $ecIds) {
            $aggregates[(int) $layerId] = [
                'matched' => array_sum(array_map(fn ($id) => $matchedByEc[$id] ?? 0, $ecIds)),
                'total' => array_sum(array_map(fn ($id) => $totals[$id] ?? 0, $ecIds)),
            ];
        }

        return $aggregates;
    }

    private static function fraction(int $matched, int $total): string
    {
        $percent = $total > 0 ? (int) round(100 * $matched / $total) : 0;

        return $matched.'/'.$total.' ('.$percent.'%)';
    }

    private static function link(string $uriKey, int $id, string $label): string
    {
        $url = '/'.trim(Nova::path(), '/').'/resources/'.$uriKey.'/'.$id;

        return '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer" class="link-default">'.e($label).'</a>';
    }

    private static function ecTrackName(?object $ecTrack, int $id): string
    {
        if ($ecTrack === null) {
            return (string) $id;
        }
        foreach ([app()->getLocale(), 'it', 'en'] as $locale) {
            $name = $ecTrack->getTranslation('name', $locale, false);
            if (! empty($name)) {
                return $name;
            }
        }

        return (string) $id;
    }
}
