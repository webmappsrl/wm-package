<?php

namespace Wm\WmPackage\Services\Import;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Services\StorageService;

class IconMappingService
{
    private const CACHE_KEY = 'geohub_icons_mapping';

    private const DEFAULT_HEIGHT = 1024;

    private const DEFAULT_PREV_SIZE = 32;

    private const CACHE_TTL = 86400;

    private StorageService $storageService;

    public function __construct(StorageService $storageService)
    {
        $this->storageService = $storageService;
    }

    public function getIconMapping(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $app = DB::connection('geohub')
                ->table('apps')
                ->where('id', 3)
                ->whereNotNull('iconmoon_selection')
                ->first(['iconmoon_selection']);

            $mapping = [];

            $this->storageService->storeIcons($app->iconmoon_selection);

            $iconmoonData = json_decode($app->iconmoon_selection, true);
            if (! $iconmoonData || ! isset($iconmoonData['icons'])) {
                return $mapping;
            }

            $height = self::DEFAULT_HEIGHT;
            $prevSize = self::DEFAULT_PREV_SIZE;
            $height2 = $height / 2;

            foreach ($iconmoonData['icons'] as $icon) {
                if (! isset($icon['icon']['paths'])) {
                    continue;
                }

                $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$height} {$height}' width='{$prevSize}' height='{$prevSize}'><circle fill=\"darkorange\"  cx='{$height2}' cy='{$height2}' r='{$height2}'/><g fill=\"white\" transform='scale(0.8 0.8) translate(100, 100)'>";
                foreach ($icon['icon']['paths'] as $path) {
                    $svg .= "<path d='{$path}'/>";
                }
                $svg .= '</g></svg>';

                $mapping[$svg] = $icon['properties']['name'];
            }

            return $mapping;
        });
    }

    public function getSvgIdentifier(string $svg): ?string
    {
        if (! $svg) {
            return null;
        }

        $mapping = $this->getIconMapping();
        if (isset($mapping[$svg])) {
            return $mapping[$svg];
        }

        return null;
    }
}
