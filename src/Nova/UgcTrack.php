<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\UgcTrack as UgcTrackModel;
use Wm\WmPackage\Nova\Actions\DownloadUgcTrackAction;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;
use Wm\WmPackage\Services\Models\UgcTrackCleanupService;

class UgcTrack extends AbstractUgcResource
{
    public static $model = UgcTrackModel::class;

    public static function label(): string
    {
        return __('Tracks');
    }

    public static function singularLabel(): string
    {
        return __('UGC Track');
    }

    public function fields(NovaRequest $request): array
    {
        $cleanup = UgcTrackCleanupService::make();

        // oc:8719: legenda sulla mappa solo se la traccia ha tratti ricostruiti da spiegare. Solo nel
        // dettaglio: fields() gira per ogni riga dell'index e gaps() scorre tutti i punti.
        $legend = [];
        if ($request->isResourceDetailRequest() && $this->resource instanceof UgcTrackModel) {
            $locations = $cleanup->locationsOf($this->resource);
            if ($locations !== null && $cleanup->gaps($locations) !== []) {
                $legend = [
                    ['label' => __('Recorded track'), 'color' => 'rgba(0, 0, 255, 1)', 'dash' => false],
                    [
                        'label' => __('Reconstructed segment: GPS points discarded because inaccurate and far from the route'),
                        'color' => UgcTrackModel::RECONSTRUCTED_SEGMENT_COLOR,
                        'dash' => true,
                    ],
                ];
            }
        }

        return [
            ...parent::fields($request),
            FeatureCollectionMap::make('Geometry', 'geometry')
                ->hideFromIndex()
                ->required()
                ->legend($legend)
                // oc:8719: la geometria di una traccia registrata dall'app deriva da
                // properties.locations e viene ricostruita a ogni salvataggio: un GPX caricato
                // qui verrebbe sovrascritto in silenzio.
                ->hideWhenUpdating(fn ($request, $resource) => $resource instanceof UgcTrackModel
                    && $cleanup->locationsOf($resource) !== null),
            Text::make(__('GPS cleanup'), function () use ($cleanup) {
                $locations = $this->resource instanceof UgcTrackModel ? $cleanup->locationsOf($this->resource) : null;
                if ($locations === null) {
                    return null;
                }

                $summary = $cleanup->summary($locations);
                if ($summary['discarded'] === 0) {
                    return __('No points discarded');
                }

                // Separatore decimale della lingua corrente: punto in inglese, virgola in it/de/es/fr.
                $decimal = str_starts_with(app()->getLocale(), 'en') ? '.' : ',';

                return __(':discarded of :total points discarded (max accuracy :accuracy m) · length :before km → :after km', [
                    'discarded' => $summary['discarded'],
                    'total' => $summary['total'],
                    'accuracy' => (int) round($summary['max_discarded_accuracy']),
                    'before' => number_format($summary['length_before_km'], 1, $decimal, ''),
                    'after' => number_format($summary['length_after_km'], 1, $decimal, ''),
                ]);
            })->onlyOnDetail(),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            ...parent::actions($request),
            new DownloadUgcTrackAction,
        ];
    }
}
