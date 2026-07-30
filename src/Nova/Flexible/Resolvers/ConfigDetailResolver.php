<?php

namespace Wm\WmPackage\Nova\Flexible\Resolvers;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Whitecube\NovaFlexibleContent\Layouts\Layout;
use Whitecube\NovaFlexibleContent\Value\ResolverInterface;

class ConfigDetailResolver implements ResolverInterface
{
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
            default => ['box_type' => $layout->name()] + $layout->getAttributes(),
        };
    }

    protected function hydrateAttributesForGroup(array $group): array
    {
        return Arr::except($group, ['box_type']);
    }
}
