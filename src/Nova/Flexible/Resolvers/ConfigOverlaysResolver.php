<?php

namespace Wm\WmPackage\Nova\Flexible\Resolvers;

use Illuminate\Support\Collection;
use Whitecube\NovaFlexibleContent\Layouts\Layout;
use Whitecube\NovaFlexibleContent\Value\ResolverInterface;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Nova\Traits\HasFlexibleTranslatableFields;

class ConfigOverlaysResolver implements ResolverInterface
{
    use HasFlexibleTranslatableFields;
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
        $value = $resource->{$attribute};

        if (! $value) {
            return collect();
        }

        $data = is_string($value) ? json_decode($value, true) : $value;

        if (! isset($data['OVERLAYS']) || empty($data['OVERLAYS'])) {
            return collect();
        }

        $result = collect();

        foreach ($data['OVERLAYS'] as $item) {
            if (isset($item['box_type'])) {
                $layout = $layouts->find($item['box_type']);

                if ($layout) {
                    $attributes = array_filter($item, fn($key) => $key !== 'box_type', ARRAY_FILTER_USE_KEY);
                    $result->push($layout->duplicateAndHydrate(uniqid('', true), $attributes));
                }
            }
        }

        return $result;
    }

    /**
     * Save the Flexible field's content somewhere the get method will be able to access it.
     *
     * @param  mixed  $resource
     * @param  string  $attribute
     * @param  Collection<int, Layout>  $groups
     * @return mixed
     */
    public function set($resource, $attribute, $groups)
    {
        if ($groups->isEmpty()) {
            $resource->{$attribute} = ['OVERLAYS' => []];

            return $resource;
        }

        $overlaysData = [];

        foreach ($groups as $layout) {
            $element = ['box_type' => $layout->name()];

            foreach ($layout->getAttributes() as $key => $val) {
                if ($key === 'label') {
                    $val = $this->decodeTranslatableValue($val);
                    if ($val !== []) {
                        $element[$key] = $val;
                    }
                } elseif ($key === 'feature_collection' && $val) {
                    $element[$key] = (int) $val;
                } elseif (! is_null($val) && $val !== '') {
                    $element[$key] = $val;
                }
            }

            if ($layout->name() === 'feature_collection' && isset($element['feature_collection'])) {
                $fc = FeatureCollection::find($element['feature_collection']);
                if ($fc) {
                    $element['name'] = $fc->name;
                }
            }

            $overlaysData[] = $element;
        }

        $resource->{$attribute} = ['OVERLAYS' => $overlaysData];

        return $resource;
    }
}
