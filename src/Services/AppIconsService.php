<?php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\DB;

class AppIconsService extends BaseService
{
    private $icons = [];

    public function __construct()
    {
        $this->icons = $this->icons();
    }

    public function writeIconsOnAws(int $appId)
    {
        $this->icons = $this->icons();

        StorageService::make()->storeAppIcons($appId, json_encode($this->icons));

        return $this->icons;
    }

    public function icons(): array
    {
        $icons = [];

        $iconNames = array_merge(
            $this->getIconsFromTable('taxonomy_poi_types'),
            $this->getIconsFromTable('taxonomy_activities'),
            $this->getIconsFromTable('taxonomy_themes'),
        );

        $iconsData = \Wm\WmPackage\Helpers\GlobalFileHelper::getJsonContent('icons.json', 'icons');
        $height = ($iconsData['height']) ? $iconsData['height'] : 1024;
        $height2 = $height / 2;

        foreach ($iconsData['icons'] as $icon) {
            if (isset($icon['properties']['name']) && isset($icon['icon']['paths'])) {
                $name = $icon['properties']['name'];

                if (in_array($name, $iconNames)) {
                    $prevSize = isset($icon['properties']['prevSize']) ? $icon['properties']['prevSize'] : 32;
                    $paths = $icon['icon']['paths'];

                    $svg =
                    <<<SVG
                        <svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$height} {$height}' width='{$prevSize}' height='{$prevSize}'>
                            <circle fill="darkorange" cx='{$height2}' cy='{$height2}' r='{$height2}'/>
                            <g fill="white" transform='scale(0.8 0.8) translate(100, 100)'>
                    SVG;
                    foreach ($paths as $path) {
                        $svg .= "<path d='{$path}'/>";
                    }
                    $svg .=
                    <<<SVG
                            </g>
                        </svg>
                    SVG;

                    $icons[$name] = $svg;
                }
            }
        }

        return $icons;
    }

    public function existIcon(string $iconName): bool
    {
        return isset($this->icons[$iconName]);
    }

    private function getIconsFromTable(string $tableName): array
    {
        return DB::table($tableName)->pluck('icon')->toArray();
    }
}
