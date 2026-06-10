<?php

namespace Wm\WmPackage\Nova\Fields\BboxField;

use Laravel\Nova\Fields\Field;

class BboxField extends Field
{
    public $component = 'bbox-field';

    public function resolve($resource, ?string $attribute = null): void
    {
        parent::resolve($resource, $attribute);
        $this->withMeta($this->resolveBboxMeta($this->value));
    }

    public function fillModelWithData(object $model, mixed $value, string $attribute): void
    {
        $model->{$attribute} = ($value !== null && $value !== '') ? $value : null;
    }

    private function resolveBboxMeta(?string $bbox): array
    {
        if (empty($bbox)) {
            return ['bboxValue' => null];
        }
        $decoded = json_decode($bbox, true);
        if (! is_array($decoded) || count($decoded) !== 4) {
            return ['bboxValue' => $bbox];
        }
        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $decoded);

        return [
            'bboxValue' => $bbox,
            'geojson' => [
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[
                            [$minLon, $minLat],
                            [$maxLon, $minLat],
                            [$maxLon, $maxLat],
                            [$minLon, $maxLat],
                            [$minLon, $minLat],
                        ]],
                    ],
                    'properties' => [
                        'strokeColor' => '#ff0000',
                        'strokeWidth' => 2,
                        'fillColor' => 'rgba(255, 0, 0, 0.15)',
                    ],
                ]],
            ],
            'center' => [($minLat + $maxLat) / 2, ($minLon + $maxLon) / 2],
        ];
    }
}
