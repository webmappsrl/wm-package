<?php

namespace Wm\WmPackage\Nova\Flexible\Resolvers;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Whitecube\NovaFlexibleContent\Layouts\Layout;
use Whitecube\NovaFlexibleContent\Value\ResolverInterface;
use Wm\WmPackage\Nova\Fields\FlexibleTranslatable;
use Wm\WmPackage\Nova\Flexible\ConfigDetail\InfoBoxItemRepeatable;
use Wm\WmPackage\Nova\Traits\HasFlexibleTranslatableFields;

class ConfigDetailResolver implements ResolverInterface
{
    use HasFlexibleTranslatableFields;

    /**
     * The only box_type registered today (see docs/features/8181-box-informativi-cammino/overview.md).
     * Referenced by name from HasConfigDetailPanel's detail-view preview too, so a future
     * addLayout() for a new box_type can't silently fall through the 'info'-only preview
     * branch without at least one shared place to update.
     */
    public const INFO_BOX_TYPE = 'info';

    public function get($resource, $attribute, $layouts): Collection
    {
        if (! is_object($resource)) {
            return collect();
        }

        $properties = $resource->properties ?? [];
        $groups = is_array($properties) ? ($properties['config_detail'] ?? []) : [];

        if (! is_array($groups)) {
            return collect();
        }

        $result = collect();

        foreach ($groups as $group) {
            if (! is_array($group) || ! isset($group['box_type'])) {
                continue;
            }

            $layout = $layouts->find($group['box_type']);

            if (! $layout) {
                continue;
            }

            $attributes = $this->hydrateAttributesForGroup($group);
            $result->push($layout->duplicateAndHydrate(uniqid('', true), $attributes));
        }

        return $result;
    }

    public function set($resource, $attribute, $groups)
    {
        $data = $groups->map(fn (Layout $layout) => $this->buildElement($layout))->values()->all();

        // $attribute is 'properties->config_detail'. Eloquent's own setAttribute()
        // special-cases keys containing '->' via fillJsonAttribute(), which reads
        // the current 'properties' array, merges only the 'config_detail' path,
        // and re-encodes the whole column — sibling keys (title, description,
        // form, ugc, layers, layer_id...) are preserved automatically. No manual
        // Arr::set()/reassignment needed (verified against
        // Illuminate\Database\Eloquent\Concerns\HasAttributes::setAttribute()).
        $resource->{$attribute} = $data;

        return $resource;
    }

    protected function buildElement(Layout $layout): array
    {
        return match ($layout->name()) {
            self::INFO_BOX_TYPE => $this->buildInfoElement($layout),
            default => ['box_type' => $layout->name()] + $layout->getAttributes(),
        };
    }

    protected function buildInfoElement(Layout $layout): array
    {
        $items = $layout->getAttributes()['items'] ?? [];

        if (is_string($items)) {
            $decoded = json_decode($items, true);
            $items = is_array($decoded) ? $decoded : [];
        }

        $locales = Config::get('wm-tab-translatable.locales', []);

        $normalized = array_values(array_map(function ($block) use ($locales) {
            $fields = is_array($block) ? ($block['fields'] ?? []) : [];
            $title = $this->decodeTranslatableValue($fields['title'] ?? null);

            $content = [];
            foreach ($locales as $locale) {
                $value = $fields[FlexibleTranslatable::richTextAttributeFor('content', $locale)] ?? null;
                if ($value !== null && $value !== '') {
                    $content[$locale] = $value;
                }
            }

            return ['title' => $title, 'content' => $content];
        }, is_array($items) ? $items : []));

        return ['box_type' => self::INFO_BOX_TYPE, 'items' => $normalized];
    }

    protected function hydrateAttributesForGroup(array $group): array
    {
        if (($group['box_type'] ?? null) === self::INFO_BOX_TYPE) {
            return $this->hydrateInfoAttributes($group);
        }

        return Arr::except($group, ['box_type']);
    }

    protected function hydrateInfoAttributes(array $group): array
    {
        $items = is_array($group['items'] ?? null) ? $group['items'] : [];
        $locales = Config::get('wm-tab-translatable.locales', []);

        $blocks = array_map(function ($item) use ($locales) {
            $fields = ['title' => is_array($item['title'] ?? null) ? $item['title'] : []];

            foreach ($locales as $locale) {
                $fields[FlexibleTranslatable::richTextAttributeFor('content', $locale)] = $item['content'][$locale] ?? null;
            }

            return ['type' => InfoBoxItemRepeatable::key(), 'fields' => $fields];
        }, $items);

        return ['items' => $blocks];
    }
}
