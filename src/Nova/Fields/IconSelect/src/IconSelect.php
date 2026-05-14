<?php

namespace Wm\WmPackage\Nova\Fields\IconSelect;

use Illuminate\Support\Facades\Log;
use Laravel\Nova\Fields\Field;
use Wm\WmPackage\Helpers\GlobalFileHelper;
use Wm\WmPackage\Services\IconSvgService;

class IconSelect extends Field
{
    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'icon-select';

    /**
     * Set the available icon options for the select field.
     *
     * @return $this
     */
    public function options(array $options)
    {
        return $this->withMeta(['options' => $options]);
    }

    /**
     * Set the placeholder text for the search input.
     *
     * @return $this
     */
    public function searchPlaceholder(string $placeholder)
    {
        return $this->withMeta(['searchPlaceholder' => $placeholder]);
    }

    /**
     * Set whether the field allows multiple selections.
     *
     * @return $this
     */
    public function multiple(bool $multiple = true)
    {
        return $this->withMeta(['multiple' => $multiple]);
    }

    /**
     * Set the maximum number of items that can be selected.
     *
     * @return $this
     */
    public function maxItems(int $max)
    {
        return $this->withMeta(['maxItems' => $max]);
    }

    /**
     * Set the CSS class for icons.
     *
     * @return $this
     */
    public function iconClass(string $iconClass)
    {
        return $this->withMeta(['iconClass' => $iconClass]);
    }

    /**
     * Load options from icons.json file via GlobalFileHelper.
     *
     * @return $this
     */
    public function loadFromIconsFile()
    {
        try {
            $iconService = new IconSvgService;
            // Usa il metodo statico del GlobalFileHelper
            $iconsData = GlobalFileHelper::getJsonContent('icons.json', 'icons');
            $names = [];
            if (is_array($iconsData)) {
                // legacy format
                if (is_array($iconsData['icons'] ?? null) && isset($iconsData['icons'][0]['properties'])) {
                    foreach ($iconsData['icons'] as $icon) {
                        $name = data_get($icon, 'properties.name');
                        if (is_string($name) && $name !== '') {
                            $names[] = $name;
                        }
                    }
                } elseif (is_array($iconsData['selection'] ?? null)) {
                    foreach ($iconsData['selection'] as $sel) {
                        $name = $sel['name'] ?? null;
                        if (is_string($name) && $name !== '') {
                            $names[] = $name;
                        }
                    }
                }
            }

            $options = [];
            foreach (array_values(array_unique($names)) as $name) {
                $svgPaths = $iconService->getSvgByName($name, wrapSvg: false);
                if (! is_string($svgPaths) || $svgPaths === '') {
                    continue;
                }

                $options[] = [
                    'value' => $name,
                    'label' => ucfirst(str_replace(['-', '_'], ' ', $name)),
                    'svg' => $svgPaths,
                ];
            }

            return $this->options($options);
        } catch (\Exception $e) {
            // In caso di errore, usa le opzioni predefinite
            Log::warning('Errore nel caricamento delle icone da icons.json: '.$e->getMessage());
        }

        return $this;
    }
}
