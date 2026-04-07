<?php

namespace Wm\WmPackage\Nova\Flexible\Resolvers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Whitecube\NovaFlexibleContent\Layouts\Layout;
use Whitecube\NovaFlexibleContent\Value\ResolverInterface;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyActivity as TaxonomyActivityModel;
use Wm\WmPackage\Models\TaxonomyPoiType as TaxonomyPoiTypeModel;
use Wm\WmPackage\Nova\Flexible\ConfigHome\HorizontalScrollItemRepeatable;

class ConfigHomeResolver implements ResolverInterface
{
    /**
     * Resolve the Flexible field's content.
     *
     * @param  mixed  $resource
     * @param  string  $attribute
     * @param  \Whitecube\NovaFlexibleContent\Layouts\Collection  $layouts
     * @return Collection<array-key, Layout>
     */
    public function get($resource, $attribute, $layouts)
    {
        $value = null;
        if (is_object($resource) && method_exists($resource, 'getRawOriginal')) {
            $value = $resource->getRawOriginal($attribute);
        }
        if (($value === null || $value === '') && is_object($resource)) {
            $value = $resource->{$attribute} ?? null;
        }

        if ($value === null || $value === '') {
            return collect();
        }

        $data = $this->decodeConfigHomePayload($value);

        if (! isset($data['HOME']) || empty($data['HOME'])) {
            return collect();
        }

        $result = collect();

        foreach ($data['HOME'] as $item) {
            $item = $this->normalizeHomeBlockRow($item);

            if (! isset($item['box_type'])) {
                continue;
            }

            $layoutName = $item['box_type'];
            if (($item['box_type'] ?? null) === 'horizontal_scroll' && ($item['item_type'] ?? null) === 'activities') {
                $layoutName = 'horizontal_scroll_activities';
            }
            if (($item['box_type'] ?? null) === 'horizontal_scroll' && ($item['item_type'] ?? null) === 'poi_types') {
                $layoutName = 'horizontal_scroll_poi_types';
            }

            $layout = $layouts->find($layoutName);

            if (! $layout) {
                continue;
            }

            $attributes = [];
            foreach ($item as $key => $val) {
                if ($key === 'box_type') {
                    continue;
                }
                if ($key === 'title' && is_array($val)) {
                    foreach ($val as $locale => $translation) {
                        $attributes['title_'.$locale] = $translation;
                    }
                } else {
                    $attributes[$key] = $val;
                }
            }

            if (($item['box_type'] ?? null) === 'horizontal_scroll') {
                $languages = Config::get('wm-app-languages.languages', []);
                $title = $item['title'] ?? [];

                if (is_array($title)) {
                    foreach (array_keys($languages) as $locale) {
                        $attributes['title_'.$locale] = $title[$locale] ?? null;
                    }
                    unset($attributes['title']);
                } elseif (is_string($title) && isset($languages['it'])) {
                    $attributes['title_it'] = $title;
                    unset($attributes['title']);
                }

                $attributes['items'] = $this->toRepeaterItems(
                    $this->normalizeHorizontalScrollItemsInput($item),
                    (string) ($item['item_type'] ?? '')
                );

                unset($attributes['item']);
                unset($attributes['activity_item'], $attributes['poi_type_item']);
            }

            $result->push($layout->duplicateAndHydrate(uniqid('', true), $attributes));
        }

        return $result;
    }

    /**
     * Save the Flexible field's content somewhere the get method will be able to access it.
     *
     * @param  mixed  $resource
     * @param  string  $attribute  Attribute name set for a Flexible field.
     * @param  Collection<int, Layout>  $groups
     * @return mixed
     */
    public function set($resource, $attribute, $groups)
    {
        if ($groups->isEmpty()) {
            $resource->{$attribute} = json_encode(['HOME' => []]);

            return $resource;
        }

        $homeData = [];

        foreach ($groups as $groupIndex => $layout) {
            $homeElement = [
                'box_type' => $layout->name(),
            ];

            foreach ($layout->getAttributes() as $key => $val) {
                if ($key === 'layer' && $val) {
                    $homeElement[$key] = (int) $val;
                } else {
                    $homeElement[$key] = $val;
                }
            }

            if ($layout->name() === 'layer' && isset($homeElement['layer'])) {
                $layerId = $homeElement['layer'];
                $layer = Layer::find($layerId);

                if ($layer) {
                    $title = $layer->getStringName();

                    if (empty($title)) {
                        $title = 'Layer #'.$layer->id;
                    }

                    $homeElement['title'] = $title;
                }
            }

            if ($layout->name() === 'horizontal_scroll_activities') {
                $homeElement['box_type'] = 'horizontal_scroll';
                $homeElement['item_type'] = 'activities';

                $languages = Config::get('wm-app-languages.languages', []);
                $title = [];

                foreach (array_keys($languages) as $locale) {
                    $key = 'title_'.$locale;

                    if (! empty($homeElement[$key])) {
                        $title[$locale] = $homeElement[$key];
                    }

                    unset($homeElement[$key]);
                }

                if (! empty($title)) {
                    $homeElement['title'] = $title;
                }

                $itemsPayload = $this->resolveRepeaterItemsPayload($attribute, $layout, $homeElement['items'] ?? null);
                $normalizedItems = $this->fromRepeaterItems($itemsPayload, 'activities');
                $rawGroupAttrs = $this->findRawFlexibleGroupAttributes($attribute, (string) $layout->inUseKey());
                if (
                    empty($normalizedItems)
                    && $this->shouldPreserveRepeaterItemsFromDb($rawGroupAttrs, $itemsPayload, $normalizedItems)
                ) {
                    $previousItems = $this->previousHorizontalScrollItemsForGroup($resource, $attribute, $groupIndex, 'activities');
                    if ($previousItems !== null && $previousItems !== []) {
                        $normalizedItems = $this->fromRepeaterItems($previousItems, 'activities');
                    }
                }
                $homeElement['items'] = ! empty($normalizedItems) ? $normalizedItems : [];
                $homeElement = $this->finalizeHorizontalScrollElement($homeElement);
            }

            if ($layout->name() === 'horizontal_scroll_poi_types') {
                $homeElement['box_type'] = 'horizontal_scroll';
                $homeElement['item_type'] = 'poi_types';

                $languages = Config::get('wm-app-languages.languages', []);
                $title = [];

                foreach (array_keys($languages) as $locale) {
                    $key = 'title_'.$locale;

                    if (! empty($homeElement[$key])) {
                        $title[$locale] = $homeElement[$key];
                    }

                    unset($homeElement[$key]);
                }

                if (! empty($title)) {
                    $homeElement['title'] = $title;
                }

                $itemsPayload = $this->resolveRepeaterItemsPayload($attribute, $layout, $homeElement['items'] ?? null);
                $normalizedItems = $this->fromRepeaterItems($itemsPayload, 'poi_types');
                $rawGroupAttrs = $this->findRawFlexibleGroupAttributes($attribute, (string) $layout->inUseKey());
                if (
                    empty($normalizedItems)
                    && $this->shouldPreserveRepeaterItemsFromDb($rawGroupAttrs, $itemsPayload, $normalizedItems)
                ) {
                    $previousItems = $this->previousHorizontalScrollItemsForGroup($resource, $attribute, $groupIndex, 'poi_types');
                    if ($previousItems !== null && $previousItems !== []) {
                        $normalizedItems = $this->fromRepeaterItems($previousItems, 'poi_types');
                    }
                }
                $homeElement['items'] = ! empty($normalizedItems) ? $normalizedItems : [];
                $homeElement = $this->finalizeHorizontalScrollElement($homeElement);
            }

            if (! in_array($layout->name(), ['horizontal_scroll_activities', 'horizontal_scroll_poi_types'], true)) {
                $homeElement = $this->finalizeNonHorizontalHomeElement($homeElement);
            }

            $homeData[] = $homeElement;
        }

        $resource->{$attribute} = json_encode(['HOME' => $homeData]);

        return $resource;
    }

    /**
     * @param  array<string, mixed>  $homeElement
     * @return array<string, mixed>
     */
    private function finalizeNonHorizontalHomeElement(array $homeElement): array
    {
        $languages = Config::get('wm-app-languages.languages', []);
        $titleData = [];

        foreach (array_keys($languages) as $locale) {
            $key = 'title_'.$locale;
            if (array_key_exists($key, $homeElement)) {
                $val = $homeElement[$key];
                if (! is_null($val) && $val !== '') {
                    $titleData[$locale] = $val;
                }
                unset($homeElement[$key]);
            }
        }

        if (! empty($titleData)) {
            if (($homeElement['box_type'] ?? null) === 'layer' && isset($homeElement['title']) && ! is_array($homeElement['title'])) {
                // keep layer string title from Layer::find
            } else {
                $homeElement['title'] = $titleData;
            }
        }

        $out = [];
        foreach ($homeElement as $key => $val) {
            if (is_null($val) || $val === '') {
                continue;
            }
            $out[$key] = $val;
        }

        return $out;
    }

    /**
     * @param  mixed  $value  JSON string o array dal DB / cast
     * @return array<string, mixed>
     */
    private function decodeConfigHomePayload($value): array
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

    /**
     * @param  mixed  $item
     * @return array<string, mixed>
     */
    private function normalizeHomeBlockRow($item): array
    {
        if (is_object($item)) {
            return json_decode(json_encode($item), true) ?: [];
        }

        return is_array($item) ? $item : [];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, mixed>
     */
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

        if (! is_array($raw)) {
            return [];
        }

        return array_values($raw);
    }

    /**
     * Ordine chiavi nel JSON: box_type, item_type, title, items.
     *
     * @param  array<string, mixed>  $homeElement
     * @return array<string, mixed>
     */
    private function finalizeHorizontalScrollElement(array $homeElement): array
    {
        $title = $homeElement['title'] ?? [];
        if (is_array($title)) {
            $title = array_filter($title, static fn ($v) => ! is_null($v) && $v !== '');
        }

        $items = $homeElement['items'] ?? [];
        if (is_array($items)) {
            $items = array_values(array_map(function ($row) {
                if (! is_array($row)) {
                    return $row;
                }
                $t = $row['title'] ?? [];
                if (is_array($t)) {
                    $row['title'] = array_filter($t, static fn ($v) => ! is_null($v) && $v !== '');
                }

                return $row;
            }, $items));
        }

        return [
            'box_type' => 'horizontal_scroll',
            'item_type' => $homeElement['item_type'] ?? null,
            'title' => is_array($title) ? $title : [],
            'items' => is_array($items) ? $items : [],
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function toRepeaterItems(array $items, string $itemType): array
    {
        $languages = array_keys(Config::get('wm-app-languages.languages', []));

        return array_values(array_map(function ($item) use ($languages) {
            if (is_array($item) && isset($item['fields']) && is_array($item['fields'])) {
                $item = $item['fields'];
            }

            $fields = [
                'res' => $item['res'] ?? null,
                'image_url' => $item['image_url'] ?? null,
            ];

            $title = $item['title'] ?? [];
            if (is_array($title)) {
                foreach ($languages as $locale) {
                    $fields['title_'.$locale] = $title[$locale] ?? null;
                }
            }

            return [
                'type' => HorizontalScrollItemRepeatable::key(),
                'fields' => $fields,
            ];
        }, $items));
    }

    /**
     * @param  mixed  $items
     * @return array<int, array<string, mixed>>
     */
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
            $imageUrl = $fields['image_url'] ?? '';
            if (empty($res)) {
                continue;
            }

            $taxonomyItem = $this->resolveTaxonomyItem($itemType, (string) $res);
            if (! is_array($taxonomyItem) || empty($taxonomyItem['res'])) {
                continue;
            }

            $title = $this->mergeHorizontalScrollItemTitle($fields, $taxonomyItem['title'] ?? []);
            if ($title === []) {
                continue;
            }

            $normalized[] = [
                'title' => $title,
                'res' => $taxonomyItem['res'],
                'image_url' => is_string($imageUrl) ? $imageUrl : '',
            ];
        }

        return $normalized;
    }

    /**
     * @param  mixed  $item
     * @return array<string, mixed>
     */
    private function extractRepeaterFields($item): array
    {
        $item = $this->normalizeRepeaterInput($item);
        if (! is_array($item)) {
            return [];
        }

        $candidates = [];

        if (isset($item['fields'])) {
            $candidates[] = $item['fields'];
        }

        if (isset($item['attributes'])) {
            $candidates[] = $item['attributes'];
        }

        if (isset($item['value'])) {
            $candidates[] = $item['value'];
        }

        $candidates[] = $item;

        foreach ($candidates as $candidate) {
            $candidate = $this->normalizeRepeaterInput($candidate);
            if (! is_array($candidate)) {
                continue;
            }

            $res = $candidate['res'] ?? null;
            $imageUrl = $candidate['image_url'] ?? '';

            if (! empty($res)) {
                $out = [
                    'res' => $res,
                    'image_url' => is_string($imageUrl) ? $imageUrl : '',
                ];
                foreach ($candidate as $k => $v) {
                    if (is_string($k) && str_starts_with($k, 'title_')) {
                        $out[$k] = $v;
                    }
                }

                return $out;
            }
        }

        return [];
    }

    /**
     * Titoli per item da Nova (`title_{locale}`) sovrascrivono le etichette taxonomy per lingua; vuoto = fallback taxonomy.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, string>  $taxonomyTitle
     * @return array<string, string>
     */
    private function mergeHorizontalScrollItemTitle(array $fields, array $taxonomyTitle): array
    {
        $languages = array_keys(Config::get('wm-app-languages.languages', []));
        $merged = [];

        foreach ($languages as $locale) {
            $key = 'title_'.$locale;
            $custom = isset($fields[$key]) ? trim((string) $fields[$key]) : '';
            if ($custom !== '') {
                $merged[$locale] = $custom;
            } elseif (isset($taxonomyTitle[$locale]) && is_string($taxonomyTitle[$locale]) && $taxonomyTitle[$locale] !== '') {
                $merged[$locale] = $taxonomyTitle[$locale];
            }
        }

        if ($merged === [] && $taxonomyTitle !== []) {
            return $taxonomyTitle;
        }

        return $merged;
    }

    /**
     * @return array{title: array<string, string>, res: string}|array{}
     */
    private function resolveTaxonomyItem(string $itemType, string $res): array
    {
        if ($itemType === 'activities') {
            $activity = TaxonomyActivityModel::query()
                ->where('identifier', $res)
                ->first(['identifier', 'name']);

            if (! $activity || empty($activity->identifier)) {
                return [];
            }

            $title = $this->normalizeTaxonomyNameForTitle($activity->name, $activity->identifier);

            if ($title === []) {
                return [];
            }

            return [
                'title' => $title,
                'res' => $activity->identifier,
            ];
        }

        if ($itemType === 'poi_types') {
            $identifier = str_starts_with($res, 'poi_type_')
                ? substr($res, strlen('poi_type_'))
                : $res;

            $poiType = TaxonomyPoiTypeModel::query()
                ->where('identifier', $identifier)
                ->first(['identifier', 'name']);

            if (! $poiType || empty($poiType->identifier)) {
                return [];
            }

            $title = $this->normalizeTaxonomyNameForTitle($poiType->name, 'poi_type_'.$poiType->identifier);

            if ($title === []) {
                return [];
            }

            return [
                'title' => $title,
                'res' => 'poi_type_'.$poiType->identifier,
            ];
        }

        return [];
    }

    /**
     * @param  mixed  $name
     * @return array<string, string>
     */
    private function normalizeTaxonomyNameForTitle($name, string $fallback): array
    {
        if (is_array($name) && ! empty($name)) {
            return $name;
        }

        if (is_string($name) && $name !== '') {
            return ['it' => $name, 'en' => $name];
        }

        return ['it' => $fallback, 'en' => $fallback];
    }

    /**
     * Il Repeater dentro Flexible a volte non espone ancora `items` in getAttributes(); usiamo il payload grezzo della request.
     *
     * @param  mixed  $fromAttributes
     * @return mixed
     */
    private function resolveRepeaterItemsPayload(string $flexibleAttribute, Layout $layout, $fromAttributes)
    {
        if (! $this->repeaterPayloadLooksEmpty($fromAttributes)) {
            return $fromAttributes;
        }

        $raw = $this->findRawFlexibleGroupAttributes($flexibleAttribute, (string) $layout->inUseKey());

        return $raw['items'] ?? $fromAttributes;
    }

    /**
     * @param  mixed  $payload
     */
    private function repeaterPayloadLooksEmpty($payload): bool
    {
        if ($payload === null || $payload === '') {
            return true;
        }

        $normalized = $this->normalizeRepeaterInput($payload);

        if (! is_array($normalized)) {
            return true;
        }

        return count($normalized) === 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRawFlexibleGroupAttributes(string $flexibleAttribute, string $groupKey): ?array
    {
        $request = request();
        $raw = $request->input($flexibleAttribute);

        if (! is_array($raw)) {
            return null;
        }

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['key'] ?? null) === $groupKey) {
                $attrs = $row['attributes'] ?? null;

                return is_array($attrs) ? $attrs : null;
            }
        }

        return null;
    }

    /**
     * @return array<int, mixed>|null
     */
    private function previousHorizontalScrollItemsForGroup($resource, string $attribute, int $groupIndex, string $expectedItemType): ?array
    {
        $value = null;
        if (is_object($resource) && method_exists($resource, 'getRawOriginal')) {
            $value = $resource->getRawOriginal($attribute);
        }
        if (($value === null || $value === '') && is_object($resource)) {
            $value = $resource->{$attribute} ?? null;
        }

        $payload = $this->decodeConfigHomePayload($value);
        $home = $payload['HOME'] ?? [];
        if (! isset($home[$groupIndex])) {
            return null;
        }

        $block = $this->normalizeHomeBlockRow($home[$groupIndex]);
        if (($block['box_type'] ?? null) !== 'horizontal_scroll') {
            return null;
        }
        if (($block['item_type'] ?? null) !== $expectedItemType) {
            return null;
        }

        $items = $block['items'] ?? null;
        if (! is_array($items) || $items === []) {
            return null;
        }

        return array_values($items);
    }

    /**
     * @param  array<string, mixed>|null  $rawGroupAttributes
     */
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
            if (is_array($decoded) && $decoded === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  mixed  $items
     * @return mixed
     */
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
