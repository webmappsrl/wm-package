<?php

namespace Wm\WmPackage\Nova\Flexible\Resolvers;

use Illuminate\Support\Collection;
use Whitecube\NovaFlexibleContent\Layouts\Layout;
use Whitecube\NovaFlexibleContent\Value\ResolverInterface;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyActivity as TaxonomyActivityModel;
use Wm\WmPackage\Models\TaxonomyPoiType as TaxonomyPoiTypeModel;
use Wm\WmPackage\Nova\Flexible\ConfigHome\HorizontalScrollItemRepeatable;
use Wm\WmPackage\Nova\Traits\HasFlexibleTranslatableFields;

class ConfigHomeResolver implements ResolverInterface
{
    use HasFlexibleTranslatableFields;

    public function get($resource, $attribute, $layouts): Collection
    {
        $value = is_object($resource) && method_exists($resource, 'getRawOriginal')
            ? $resource->getRawOriginal($attribute)
            : null;

        if (($value === null || $value === '') && is_object($resource)) {
            $value = $resource->{$attribute} ?? null;
        }

        if ($value === null || $value === '') {
            return collect();
        }

        $data = $this->decodePayload($value);

        if (empty($data['HOME'])) {
            return collect();
        }

        $result = collect();

        foreach ($data['HOME'] as $item) {
            $item = $this->normalizeRow($item);

            if (! isset($item['box_type'])) {
                continue;
            }

            $layoutName = $this->resolveLayoutName($item);
            $layout = $layouts->find($layoutName);

            if (! $layout) {
                continue;
            }

            $attributes = $this->getAttributesForItem($item);
            $result->push($layout->duplicateAndHydrate(uniqid('', true), $attributes));
        }

        return $result;
    }

    public function set($resource, $attribute, $groups)
    {
        if ($groups->isEmpty()) {
            $resource->{$attribute} = json_encode(['HOME' => []]);

            return $resource;
        }

        $homeData = [];

        foreach ($groups as $groupIndex => $layout) {
            $homeData[] = $this->buildElement($resource, $attribute, $layout, $groupIndex);
        }

        $resource->{$attribute} = json_encode(['HOME' => $homeData]);

        return $resource;
    }

    // -------------------------------------------------------------------------
    // GET helpers
    // -------------------------------------------------------------------------

    private function resolveLayoutName(array $item): string
    {
        if (($item['box_type'] ?? null) === 'horizontal_scroll') {
            return match ($item['item_type'] ?? null) {
                'activities' => 'horizontal_scroll_activities',
                'poi_types' => 'horizontal_scroll_poi_types',
                default => 'horizontal_scroll',
            };
        }

        return $item['box_type'];
    }

    private function getAttributesForItem(array $item): array
    {
        $attributes = array_filter($item, fn ($key) => $key !== 'box_type', ARRAY_FILTER_USE_KEY);

        if (($item['box_type'] ?? null) === 'horizontal_scroll') {
            $attributes['items'] = $this->toRepeaterItems(
                $this->normalizeHorizontalScrollItemsInput($item),
                (string) ($item['item_type'] ?? '')
            );
            unset($attributes['item'], $attributes['activity_item'], $attributes['poi_type_item']);
        }

        return $attributes;
    }

    // -------------------------------------------------------------------------
    // SET helpers — one method per box_type
    // -------------------------------------------------------------------------

    private function buildElement($resource, string $attribute, Layout $layout, int $groupIndex): array
    {
        return match ($layout->name()) {
            'layer' => $this->buildLayerElement($layout),
            'horizontal_scroll_activities' => $this->buildHorizontalScrollElement($resource, $attribute, $layout, $groupIndex, 'activities'),
            'horizontal_scroll_poi_types' => $this->buildHorizontalScrollElement($resource, $attribute, $layout, $groupIndex, 'poi_types'),
            default => $this->buildGenericElement($layout),
        };
    }

    private function buildGenericElement(Layout $layout): array
    {
        $element = ['box_type' => $layout->name()];

        foreach ($layout->getAttributes() as $key => $val) {
            if ($key === 'title') {
                $val = $this->decodeTranslatableValue($val);
                if ($val !== []) {
                    $element[$key] = $val;
                }
            } elseif (! is_null($val) && $val !== '') {
                $element[$key] = $val;
            }
        }

        return $element;
    }

    private function buildLayerElement(Layout $layout): array
    {
        $element = ['box_type' => 'layer'];

        foreach ($layout->getAttributes() as $key => $val) {
            if ($key === 'layer' && $val) {
                $element[$key] = (int) $val;
            } elseif (! is_null($val) && $val !== '') {
                $element[$key] = $val;
            }
        }

        if (isset($element['layer'])) {
            $layer = Layer::find($element['layer']);
            if ($layer) {
                $element['title'] = $layer->getStringName() ?: 'Layer #'.$layer->id;
            }
        }

        return $element;
    }

    private function buildHorizontalScrollElement($resource, string $attribute, Layout $layout, int $groupIndex, string $itemType): array
    {
        $element = ['box_type' => 'horizontal_scroll', 'item_type' => $itemType];

        foreach ($layout->getAttributes() as $key => $val) {
            if (! is_null($val) && $val !== '') {
                $element[$key] = $val;
            }
        }

        $itemsPayload = $this->resolveRepeaterItemsPayload($attribute, $layout, $element['items'] ?? null);
        $normalizedItems = $this->fromRepeaterItems($itemsPayload, $itemType);
        $rawGroupAttrs = $this->findRawFlexibleGroupAttributes($attribute, (string) $layout->inUseKey());

        if (empty($normalizedItems) && $this->shouldPreserveRepeaterItemsFromDb($rawGroupAttrs, $itemsPayload, $normalizedItems)) {
            $previousItems = $this->previousHorizontalScrollItemsForGroup($resource, $attribute, $groupIndex, $itemType);
            if ($previousItems !== null && $previousItems !== []) {
                $normalizedItems = $this->fromRepeaterItems($previousItems, $itemType);
            }
        }

        $element['items'] = ! empty($normalizedItems) ? $normalizedItems : [];

        return $this->finalizeHorizontalScrollElement($element);
    }

    // -------------------------------------------------------------------------
    // Repeater helpers
    // -------------------------------------------------------------------------

    private function toRepeaterItems(array $items, string $itemType): array
    {
        return array_values(array_map(function ($item) {
            if (is_array($item) && isset($item['fields']) && is_array($item['fields'])) {
                $item = $item['fields'];
            }

            return [
                'type' => HorizontalScrollItemRepeatable::key(),
                'fields' => [
                    'res' => $item['res'] ?? null,
                    'image_url' => $item['image_url'] ?? null,
                    'title' => is_array($item['title'] ?? null) ? $item['title'] : [],
                ],
            ];
        }, $items));
    }

    private function fromRepeaterItems($items, string $itemType): array
    {
        $items = $this->normalizeRepeaterInput($items);

        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            $fields = $this->extractRepeaterFields($item);
            $res = $fields['res'] ?? null;

            if (empty($res)) {
                continue;
            }

            $taxonomyItem = $this->resolveTaxonomyItem($itemType, (string) $res);

            if (! is_array($taxonomyItem) || empty($taxonomyItem['res'])) {
                continue;
            }

            $title = $this->mergeItemTitle($fields, $taxonomyItem['title'] ?? []);

            if ($title === []) {
                continue;
            }

            $normalized[] = [
                'title' => $title,
                'res' => $taxonomyItem['res'],
                'image_url' => is_string($fields['image_url'] ?? '') ? ($fields['image_url'] ?? '') : '',
            ];
        }

        return $normalized;
    }

    private function extractRepeaterFields($item): array
    {
        $item = $this->normalizeRepeaterInput($item);

        if (! is_array($item)) {
            return [];
        }

        foreach ([$item['fields'] ?? null, $item['attributes'] ?? null, $item['value'] ?? null, $item] as $candidate) {
            $candidate = $this->normalizeRepeaterInput($candidate);

            if (! is_array($candidate)) {
                continue;
            }

            $res = $candidate['res'] ?? null;

            if (! empty($res)) {
                return [
                    'res' => $res,
                    'image_url' => is_string($candidate['image_url'] ?? '') ? ($candidate['image_url'] ?? '') : '',
                    'title' => is_array($candidate['title'] ?? null) ? $candidate['title'] : [],
                ];
            }
        }

        return [];
    }

    private function mergeItemTitle(array $fields, array $taxonomyTitle): array
    {
        $customTitle = is_array($fields['title'] ?? null) ? $fields['title'] : [];
        $merged = [];

        foreach ($taxonomyTitle as $locale => $taxonomyValue) {
            $custom = trim((string) ($customTitle[$locale] ?? ''));
            $merged[$locale] = $custom !== '' ? $custom : $taxonomyValue;
        }

        foreach ($customTitle as $locale => $custom) {
            if (! isset($merged[$locale]) && trim((string) $custom) !== '') {
                $merged[$locale] = trim((string) $custom);
            }
        }

        return $merged !== [] ? $merged : $taxonomyTitle;
    }

    private function resolveTaxonomyItem(string $itemType, string $res): array
    {
        if ($itemType === 'activities') {
            $activity = TaxonomyActivityModel::query()
                ->where('identifier', $res)
                ->first(['identifier', 'name']);

            if (! $activity || empty($activity->identifier)) {
                return [];
            }

            $title = $this->normalizeTaxonomyTitle($activity->name, $activity->identifier);

            return $title !== [] ? ['title' => $title, 'res' => $activity->identifier] : [];
        }

        if ($itemType === 'poi_types') {
            $identifier = str_starts_with($res, 'poi_type_') ? substr($res, 9) : $res;

            $poiType = TaxonomyPoiTypeModel::query()
                ->where('identifier', $identifier)
                ->first(['identifier', 'name']);

            if (! $poiType || empty($poiType->identifier)) {
                return [];
            }

            $title = $this->normalizeTaxonomyTitle($poiType->name, 'poi_type_'.$poiType->identifier);

            return $title !== [] ? ['title' => $title, 'res' => 'poi_type_'.$poiType->identifier] : [];
        }

        return [];
    }

    private function normalizeTaxonomyTitle($name, string $fallback): array
    {
        if (is_array($name) && ! empty($name)) {
            return $name;
        }

        if (is_string($name) && $name !== '') {
            return ['it' => $name, 'en' => $name];
        }

        return ['it' => $fallback, 'en' => $fallback];
    }

    // -------------------------------------------------------------------------
    // Finalize helpers
    // -------------------------------------------------------------------------

    private function finalizeHorizontalScrollElement(array $element): array
    {
        $title = is_array($element['title'] ?? null)
            ? array_filter($element['title'], static fn ($v) => ! is_null($v) && $v !== '')
            : [];

        $items = array_values(array_map(function ($row) {
            if (is_array($row) && is_array($row['title'] ?? null)) {
                $row['title'] = array_filter($row['title'], static fn ($v) => ! is_null($v) && $v !== '');
            }

            return $row;
        }, is_array($element['items'] ?? null) ? $element['items'] : []));

        return [
            'box_type' => 'horizontal_scroll',
            'item_type' => $element['item_type'] ?? null,
            'title' => $title,
            'items' => $items,
        ];
    }

    // -------------------------------------------------------------------------
    // Payload / request helpers
    // -------------------------------------------------------------------------

    private function decodePayload($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function normalizeRow($item): array
    {
        if (is_object($item)) {
            return json_decode(json_encode($item), true) ?: [];
        }

        return is_array($item) ? $item : [];
    }

    private function normalizeHorizontalScrollItemsInput(array $item): array
    {
        $raw = $item['items'] ?? null;

        if ($raw === null || $raw === '') {
            return [];
        }

        if ($raw instanceof Collection) {
            $raw = $raw->all();
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? array_values($decoded) : [];
        }

        if (is_object($raw)) {
            $raw = json_decode(json_encode($raw), true);
        }

        return is_array($raw) ? array_values($raw) : [];
    }

    private function resolveRepeaterItemsPayload(string $flexibleAttribute, Layout $layout, $fromAttributes)
    {
        if (! $this->repeaterPayloadLooksEmpty($fromAttributes)) {
            return $fromAttributes;
        }

        $raw = $this->findRawFlexibleGroupAttributes($flexibleAttribute, (string) $layout->inUseKey());

        return $raw['items'] ?? $fromAttributes;
    }

    private function repeaterPayloadLooksEmpty($payload): bool
    {
        if ($payload === null || $payload === '') {
            return true;
        }

        $normalized = $this->normalizeRepeaterInput($payload);

        return ! is_array($normalized) || count($normalized) === 0;
    }

    private function findRawFlexibleGroupAttributes(string $flexibleAttribute, string $groupKey): ?array
    {
        $raw = request()->input($flexibleAttribute);

        if (! is_array($raw)) {
            return null;
        }

        foreach ($raw as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $groupKey) {
                $attrs = $row['attributes'] ?? null;

                return is_array($attrs) ? $attrs : null;
            }
        }

        return null;
    }

    private function previousHorizontalScrollItemsForGroup($resource, string $attribute, int $groupIndex, string $expectedItemType): ?array
    {
        $value = is_object($resource) && method_exists($resource, 'getRawOriginal')
            ? $resource->getRawOriginal($attribute)
            : null;

        if (($value === null || $value === '') && is_object($resource)) {
            $value = $resource->{$attribute} ?? null;
        }

        $payload = $this->decodePayload($value);
        $home = $payload['HOME'] ?? [];

        if (! isset($home[$groupIndex])) {
            return null;
        }

        $block = $this->normalizeRow($home[$groupIndex]);

        if (($block['box_type'] ?? null) !== 'horizontal_scroll' || ($block['item_type'] ?? null) !== $expectedItemType) {
            return null;
        }

        $items = $block['items'] ?? null;

        return is_array($items) && $items !== [] ? array_values($items) : null;
    }

    private function shouldPreserveRepeaterItemsFromDb(?array $rawGroupAttributes, mixed $itemsPayload, array $normalizedItems): bool
    {
        if ($normalizedItems !== []) {
            return false;
        }

        if (! $this->repeaterPayloadLooksEmpty($itemsPayload)) {
            return false;
        }

        if ($rawGroupAttributes !== null && array_key_exists('items', $rawGroupAttributes)) {
            if ($this->isExplicitEmptyRepeaterItems($rawGroupAttributes['items'])) {
                return false;
            }
        }

        return true;
    }

    private function isExplicitEmptyRepeaterItems(mixed $submitted): bool
    {
        if ($submitted === [] || $submitted === null) {
            return true;
        }

        if (is_string($submitted)) {
            $trimmed = trim($submitted);
            if ($trimmed === '' || $trimmed === '[]' || $trimmed === 'null') {
                return true;
            }

            $decoded = json_decode($submitted, true);

            return is_array($decoded) && $decoded === [];
        }

        return false;
    }

    private function normalizeRepeaterInput($items)
    {
        if ($items instanceof Collection) {
            return $items->all();
        }

        if (is_string($items)) {
            $decoded = json_decode($items, true);

            return is_array($decoded) ? $decoded : $items;
        }

        if (is_object($items)) {
            return json_decode(json_encode($items), true);
        }

        return $items;
    }
}
