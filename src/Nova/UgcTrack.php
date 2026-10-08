<?php

namespace Wm\WmPackage\Nova;

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

        // oc:8719: legenda sulla mappa solo se la traccia ha tratti ricostruiti da spiegare.
        // oc:8742: dati tecnici sotto la mappa. Entrambi solo nel dettaglio: fields() gira per ogni
        // riga dell'index e gaps() scorre tutti i punti.
        $legend = [];
        $technicalData = [];
        if ($request->isResourceDetailRequest() && $this->resource instanceof UgcTrackModel) {
            $technicalData = $this->technicalDataRows();
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
                // oc:8742: dati tecnici di properties.stats sotto la mappa, come il profilo altimetrico.
                ->technicalData($technicalData)
                // oc:8719: la geometria di una traccia registrata dall'app deriva da
                // properties.locations e viene ricostruita a ogni salvataggio: un GPX caricato
                // qui verrebbe sovrascritto in silenzio.
                ->hideWhenUpdating(fn ($request, $resource) => $resource instanceof UgcTrackModel
                    && $cleanup->locationsOf($resource) !== null),
        ];
    }

    /**
     * Righe dei dati tecnici (oc:8742) da properties.stats, gli stessi numeri dell'app.
     * Separatore decimale della lingua corrente: punto in inglese, virgola in it/de/es/fr.
     * Valore null → «—». Nessuna riga se la traccia non ha stats.
     *
     * @return list<array{label: string, value: string}>
     */
    private function technicalDataRows(): array
    {
        $stats = $this->resource->properties['stats'] ?? null;
        if (! is_array($stats)) {
            return [];
        }

        $decimal = str_starts_with(app()->getLocale(), 'en') ? '.' : ',';
        $format = fn (string $key, int $decimals, string $unit) => is_numeric($stats[$key] ?? null)
            ? number_format((float) $stats[$key], $decimals, $decimal, '').' '.$unit
            : '—';

        return [
            ['label' => __('Distance'), 'value' => $format('distance', 2, 'km')],
            ['label' => __('Ascent'), 'value' => $format('ascent', 0, 'm')],
            ['label' => __('Descent'), 'value' => $format('descent', 0, 'm')],
            ['label' => __('Min elevation'), 'value' => $format('ele_min', 0, 'm')],
            ['label' => __('Max elevation'), 'value' => $format('ele_max', 0, 'm')],
            ['label' => __('Time'), 'value' => $format('duration', 0, 'min')],
            ['label' => __('Moving time'), 'value' => $format('duration_moving', 0, 'min')],
            ['label' => __('Average speed'), 'value' => $format('avg_speed', 1, 'km/h')],
            ['label' => __('Max speed'), 'value' => $format('max_speed', 1, 'km/h')],
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
