<?php

namespace Wm\WmPackage\Nova\Flexible\Resolvers;

use Illuminate\Support\Collection;
use Whitecube\NovaFlexibleContent\Layouts\Layout;
use Whitecube\NovaFlexibleContent\Value\ResolverInterface;
use Wm\WmPackage\Models\FeatureCollection;

class ConfigOverlaysResolver implements ResolverInterface
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
                    $attributes = [];
                    foreach ($item as $key => $val) {
                        if ($key === 'box_type') {
                            continue;
                        }
                        // Expand label array into label_{locale} fields
                        if ($key === 'label' && is_array($val)) {
                            foreach ($val as $locale => $translation) {
                                $attributes['label_'.$locale] = $translation;
                            }
                        } else {
                            $attributes[$key] = $val;
                        }
                    }

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
            $element = [
                'box_type' => $layout->name(),
            ];

            $labelData = [];
            foreach ($layout->getAttributes() as $key => $val) {
                if ($key === 'feature_collection' && $val) {
                    $element[$key] = (int) $val;
                } elseif (str_starts_with($key, 'label_') && $val) {
                    $locale = substr($key, 6);
                    $labelData[$locale] = $val;
                } else {
                    $element[$key] = $val;
                }
            }

            if (! empty($labelData)) {
                $element['label'] = $labelData;
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
