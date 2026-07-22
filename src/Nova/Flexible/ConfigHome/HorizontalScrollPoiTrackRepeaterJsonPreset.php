<?php

namespace Wm\WmPackage\Nova\Flexible\ConfigHome;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Nova\Fields\Repeater\Presets\JSON;
use Laravel\Nova\Fields\Repeater\RepeatableCollection;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Support\Fluent;

/**
 * JSON preset for the `items` Repeater on the `config_home` `horizontal_scroll_poi_track` layout.
 *
 * Normalizes Whitecube Flexible layout attributes (Collection, JSON string, or saved config rows with
 * `title`/`image_url`/`poi_id`/`track_id`) into Nova repeater blocks `{ type, fields }`.
 */
class HorizontalScrollPoiTrackRepeaterJsonPreset extends JSON
{
    /**
     * @param  Model|Fluent|object|array  $model
     */
    public function get(NovaRequest $request, $model, string $attribute, RepeatableCollection $repeatables): Collection
    {
        $raw = $this->extractRawItems($model, $attribute);
        $blocks = $this->normalizeToBlocks($raw);

        if ($blocks !== []) {
            return RepeatableCollection::make($blocks)
                ->map(static function (array $block) use ($repeatables) {
                    return $repeatables->newRepeatableByKey(
                        $block['type'],
                        $block['fields'] ?? []
                    );
                });
        }

        return parent::get($request, $model, $attribute, $repeatables);
    }

    /**
     * @param  object|array  $model
     */
    private function extractRawItems($model, string $attribute): mixed
    {
        if (is_array($model)) {
            return $model[$attribute] ?? null;
        }

        if (! is_object($model)) {
            return null;
        }

        if (method_exists($model, 'getAttributes')) {
            $attrs = $model->getAttributes();
            if (array_key_exists($attribute, $attrs)) {
                return $attrs[$attribute];
            }
        }

        if ($model instanceof \ArrayAccess && isset($model[$attribute])) {
            return $model[$attribute];
        }

        if (method_exists($model, 'getAttribute')) {
            return $model->getAttribute($attribute);
        }

        return null;
    }

    /**
     * @return array<int, array{type: string, fields: array<string, mixed>}>
     */
    private function normalizeToBlocks(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if ($raw instanceof Collection) {
            $raw = $raw->all();
        }

        if (is_object($raw) && ! ($raw instanceof \ArrayAccess)) {
            $raw = json_decode(json_encode($raw), true);
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            if ($raw instanceof \Traversable) {
                $raw = iterator_to_array($raw);
            } else {
                $raw = json_decode(json_encode($raw), true);
            }
        }

        if (! is_array($raw)) {
            return [];
        }

        $rows = array_values($raw);

        if ($rows === []) {
            return [];
        }

        $typeKey = HorizontalScrollPoiTrackItemRepeatable::key();
        $blocks = [];

        foreach ($rows as $row) {
            if ($row instanceof Collection) {
                $row = $row->all();
            }

            if (is_object($row)) {
                $row = json_decode(json_encode($row), true);
            }

            if (! is_array($row)) {
                continue;
            }

            if (isset($row['fields']) && is_object($row['fields'])) {
                $row['fields'] = json_decode(json_encode($row['fields']), true);
            }

            if (isset($row['fields']) && is_array($row['fields'])) {
                $fields = $row['fields'];
                $blockType = is_string($row['type'] ?? null) ? $row['type'] : $typeKey;

                $blocks[] = [
                    'type' => $blockType,
                    'fields' => $this->poiTrackRepeaterFieldsFromRow($fields, $row),
                ];

                continue;
            }

            $blocks[] = [
                'type' => $typeKey,
                'fields' => $this->poiTrackRepeaterFieldsFromRow($row, $row),
            ];
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $fieldSource  Nova repeater `fields` or flat saved row
     * @param  array<string, mixed>  $row  Full row (fallback source)
     * @return array<string, mixed>
     */
    private function poiTrackRepeaterFieldsFromRow(array $fieldSource, array $row): array
    {
        $customTitle = is_array($fieldSource['title'] ?? null) ? $fieldSource['title'] : [];
        $rowTitle = is_array($row['title'] ?? null) ? $row['title'] : [];
        $title = array_merge($rowTitle, array_filter($customTitle, fn ($v) => $v !== null && $v !== ''));

        return [
            'poi_id' => $fieldSource['poi_id'] ?? $row['poi_id'] ?? null,
            'track_id' => $fieldSource['track_id'] ?? $row['track_id'] ?? null,
            'image_url' => $fieldSource['image_url'] ?? $row['image_url'] ?? null,
            'title' => $title,
        ];
    }
}
