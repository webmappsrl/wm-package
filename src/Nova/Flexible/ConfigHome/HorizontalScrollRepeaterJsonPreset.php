<?php

namespace Wm\WmPackage\Nova\Flexible\ConfigHome;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Nova\Fields\Repeater\Presets\JSON;
use Laravel\Nova\Fields\Repeater\RepeatableCollection;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * JSON preset for the `items` Repeater on `config_home` horizontal-scroll layouts.
 *
 * Normalizes Whitecube Flexible layout attributes (Collection, JSON string, or saved config rows with
 * `title` / `res` / `image_url`) into Nova repeater blocks `{ type, fields }`. Other `config_home` layouts
 * do not use a Repeater.
 */
class HorizontalScrollRepeaterJsonPreset extends JSON
{
    /**
     * Hydrate the repeater from the model (Flexible layout) attributes.
     *
     * @param  \Illuminate\Database\Eloquent\Model|\Laravel\Nova\Support\Fluent|object|array  $model
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
     * Read the raw `items` from layout attributes or accessors (same source as the Flexible layout).
     *
     * @param  object|array  $model
     */
    private function extractRawItems($model, string $attribute): mixed
    {
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
     * @param  mixed  $raw
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

        $typeKey = HorizontalScrollItemRepeatable::key();
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
                    'fields' => $this->horizontalScrollRepeaterFieldsFromRow($fields, $row),
                ];

                continue;
            }

            $blocks[] = [
                'type' => $typeKey,
                'fields' => $this->horizontalScrollRepeaterFieldsFromRow($row, $row),
            ];
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $fieldSource  Nova repeater `fields` or flat saved row
     * @param  array<string, mixed>  $row          Full row (for `title` object from config JSON)
     * @return array<string, mixed>
     */
    private function horizontalScrollRepeaterFieldsFromRow(array $fieldSource, array $row): array
    {
        $customTitle = is_array($fieldSource['title'] ?? null) ? $fieldSource['title'] : [];
        $rowTitle = is_array($row['title'] ?? null) ? $row['title'] : [];
        $title = array_merge($rowTitle, array_filter($customTitle, fn($v) => $v !== null && $v !== ''));

        return [
            'res' => $fieldSource['res'] ?? null,
            'image_url' => $fieldSource['image_url'] ?? null,
            'title' => $title,
        ];
    }
}
