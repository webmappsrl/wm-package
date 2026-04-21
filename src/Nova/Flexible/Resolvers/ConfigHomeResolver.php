<?php

namespace Wm\WmPackage\Nova\Flexible\Resolvers;

use Illuminate\Support\Collection;
use Whitecube\NovaFlexibleContent\Layouts\Layout;
use Whitecube\NovaFlexibleContent\Value\ResolverInterface;
use Wm\WmPackage\Models\Layer;

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
        $value = $resource->{$attribute};

        if (! $value) {
            return collect();
        }

        $data = is_string($value) ? json_decode($value, true) : $value;

        if (! isset($data['HOME']) || empty($data['HOME'])) {
            return collect();
        }

        $result = collect();

        foreach ($data['HOME'] as $item) {
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
            $resource->{$attribute} = json_encode(['HOME' => []]);

            return $resource;
        }

        $homeData = [];

        foreach ($groups as $layout) {
            $homeElement = ['box_type' => $layout->name()];

            foreach ($layout->getAttributes() as $key => $val) {
                if ($key === 'layer' && $val) {
                    $homeElement[$key] = (int) $val;
                } elseif (! is_null($val) && $val !== '') {
                    $homeElement[$key] = $val;
                }
            }

            if ($layout->name() === 'layer' && isset($homeElement['layer'])) {
                $layer = Layer::find($homeElement['layer']);
                if ($layer) {
                    $homeElement['title'] = $layer->getStringName() ?: 'Layer #'.$layer->id;
                }
            }

            $homeData[] = $homeElement;
        }

        $resource->{$attribute} = json_encode(['HOME' => $homeData]);

        return $resource;
    }
}
