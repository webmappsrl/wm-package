<?php

namespace Wm\WmPackage\Nova\Fields\IconSelect;

use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Helpers\GlobalFileHelper;
use Wm\WmPackage\Nova\Fields\Choice;
use Wm\WmPackage\Services\IconSvgService;

/**
 * La select con cui si sceglie il nome di un'icona.
 *
 * È un caso particolare di `Choice`: le opzioni sono le icone del file dello shard e il
 * valore salvato è il nome dell'icona. Tutto il resto — scelta multipla, ricerca, visibilità
 * legata a un altro campo — viene da lì.
 */
class IconSelect extends Choice
{
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
