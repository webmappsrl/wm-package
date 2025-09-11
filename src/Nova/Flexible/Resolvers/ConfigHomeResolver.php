<?php

namespace Wm\WmPackage\Nova\Flexible\Resolvers;

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
     * @return \Illuminate\Support\Collection<array-key, \Whitecube\NovaFlexibleContent\Layouts\Layout>
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
                    $attributes = [];
                    foreach ($item as $key => $val) {
                        if ($key !== 'box_type') {
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
     * @param  string  $attribute  Attribute name set for a Flexible field.
     * @param  \Illuminate\Support\Collection<int, \Whitecube\NovaFlexibleContent\Layouts\Layout>  $groups
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
            $homeElement = [
                'box_type' => $layout->name(),
            ];

            // Merge all attributes
            foreach ($layout->getAttributes() as $key => $val) {
                // Assicuriamoci che 'layer' sia sempre un numero intero
                if ($key === 'layer' && $val) {
                    $homeElement[$key] = (int) $val;
                } else {
                    $homeElement[$key] = $val;
                }
            }

            // Se è un layout di tipo Layer, aggiungiamo automaticamente il titolo del layer
            if ($layout->name() === 'layer' && isset($homeElement['layer'])) {
                $layerId = $homeElement['layer'];
                $layer = Layer::find($layerId);

                if ($layer) {
                    $title = $layer->getStringName();

                    if (empty($title)) {
                        $title = 'Layer #'.$layer->id;
                    }

                    // Aggiungiamo il titolo del layer come 'name'
                    $homeElement['title'] = $title;
                }
            }

            $homeData[] = $homeElement;
        }

        $resource->{$attribute} = json_encode(['HOME' => $homeData]);

        return $resource;
    }
}
