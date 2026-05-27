<?php

namespace Wm\WmPackage\Services;

use Wm\WmPackage\Helpers\GlobalFileHelper;

class IconSvgService
{
    /**
     * Lato in pixel dell'anteprima SVG (es. icone su storage / liste) quando si imposta width/height sull'outer {@see \Wm\WmPackage\Services\AppIconsService}.
     */
    public const PREVIEW_PIXEL_SIZE = 32;

    /**
     * @var array<string, array<int, array{d: string, attrs: array<string, mixed>}>>|null
     */
    private static ?array $pathsByName = null;

    private static ?int $height = null;

    /**
     * @return int
     */
    public function getHeight(): int
    {
        $this->ensureLoaded();

        return self::$height ?? 1024;
    }

    /**
     * @return array<int, string>
     */
    public function getPathsByName(?string $name): array
    {
        if (! is_string($name) || $name === '') {
            return [];
        }

        $this->ensureLoaded();

        $items = self::$pathsByName[$name] ?? [];

        return array_values(array_map(fn ($item) => $item['d'], $items));
    }

    public function getSvgByName(?string $name, bool $wrapSvg = true): ?string
    {
        $svgPaths = '';
        $items = [];
        if (is_string($name) && $name !== '') {
            $this->ensureLoaded();
            $items = self::$pathsByName[$name] ?? [];
        }

        if ($items === []) {
            return null;
        }

        foreach ($items as $item) {
            $d = $item['d'] ?? null;
            if (! is_string($d) || $d === '') {
                continue;
            }

            $attrs = is_array($item['attrs'] ?? null) ? $item['attrs'] : [];
            $attrString = $this->buildAttributesString($attrs);

            $svgPaths .= '<path d="'.htmlspecialchars($d).'"'.$attrString.'></path>';
        }

        if ($svgPaths === '') {
            return null;
        }

        if (! $wrapSvg) {
            return $svgPaths;
        }

        $height = $this->getHeight();

        return '<svg viewBox="0 0 '.$height.' '.$height.'" xmlns="http://www.w3.org/2000/svg">'.$svgPaths.'</svg>';
    }

    /**
     * @return array<string, string>
     */
    public function getSvgMapByName(array $names): array
    {
        $map = [];
        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }
            $svg = $this->getSvgByName($name);
            if ($svg !== null) {
                $map[$name] = $svg;
            }
        }

        return $map;
    }

    private function ensureLoaded(): void
    {
        if (self::$pathsByName !== null && self::$height !== null) {
            return;
        }

        self::$pathsByName = [];
        self::$height = 1024;

        $iconsData = GlobalFileHelper::getJsonContent('icons.json', 'icons');
        if (! is_array($iconsData)) {
            return;
        }

        // height/emSize (supports both IcoMoon formats)
        $height = $iconsData['height'] ?? data_get($iconsData, 'preferences.fontPref.metrics.emSize');
        if (is_int($height) || (is_string($height) && ctype_digit($height))) {
            self::$height = (int) $height;
        }

        // Format A (legacy): { icons: [ { properties.name, icon.paths, icon.attrs } ] }
        if (is_array($iconsData['icons'] ?? null) && isset($iconsData['icons'][0]['properties'])) {
            foreach ($iconsData['icons'] as $icon) {
                $name = data_get($icon, 'properties.name');
                $paths = data_get($icon, 'icon.paths');
                $attrs = data_get($icon, 'icon.attrs');
                if (! is_string($name) || $name === '' || ! is_array($paths)) {
                    continue;
                }

                self::$pathsByName[$name] = $this->zipPathsAndAttrs($paths, is_array($attrs) ? $attrs : []);
            }

            return;
        }

        // Format B (IcoMoon "set"): { icons: [ { paths, attrs } ], selection: [ { name } ] }
        $icons = $iconsData['icons'] ?? null;
        $selection = $iconsData['selection'] ?? null;
        if (is_array($icons) && is_array($selection)) {
            $max = min(count($icons), count($selection));
            for ($i = 0; $i < $max; $i++) {
                $name = $selection[$i]['name'] ?? null;
                $paths = $icons[$i]['paths'] ?? null;
                $attrs = $icons[$i]['attrs'] ?? null;
                if (! is_string($name) || $name === '' || ! is_array($paths)) {
                    continue;
                }

                self::$pathsByName[$name] = $this->zipPathsAndAttrs($paths, is_array($attrs) ? $attrs : []);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $paths
     * @param  array<int, mixed>  $attrs
     * @return array<int, array{d: string, attrs: array<string, mixed>}>
     */
    private function zipPathsAndAttrs(array $paths, array $attrs): array
    {
        $items = [];
        foreach ($paths as $idx => $p) {
            if (! is_string($p) || $p === '') {
                continue;
            }

            $a = $attrs[$idx] ?? [];
            $items[] = [
                'd' => $p,
                'attrs' => is_array($a) ? $a : [],
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function buildAttributesString(array $attrs): string
    {
        if ($attrs === []) {
            return '';
        }

        // Whitelist common SVG attributes we accept from IcoMoon exports.
        $allowed = [
            'fill',
            'fill-opacity',
            'stroke',
            'stroke-width',
            'stroke-linecap',
            'stroke-linejoin',
            'stroke-opacity',
            'opacity',
            'transform',
        ];

        $parts = [];
        foreach ($allowed as $key) {
            if (! array_key_exists($key, $attrs)) {
                continue;
            }

            $value = $attrs[$key];
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_int($value) || is_float($value)) {
                $value = (string) $value;
            } elseif (! is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $parts[] = $key.'="'.htmlspecialchars($value).'"';
        }

        return $parts ? ' '.implode(' ', $parts) : '';
    }
}

