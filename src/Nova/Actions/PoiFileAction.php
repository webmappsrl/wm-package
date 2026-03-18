<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Actions\Action;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Models\TaxonomyTheme;

abstract class PoiFileAction extends Action
{
    public const TAXONOMIES_SHEET_TITLE = 'POI Types Taxonomies';

    public const ERRORS_SHEET_TITLE = 'Errors';

    public const ERROR_COLUMN_NAME = 'errors';

    public const ERROR_HIGHLIGHT_COLOR = 'FFF59D'; // light yellow

    /**
     * @return string[]
     */
    protected function getValidHeaders(): array
    {
        $headers = config('wm-geohub-import.ecPois.validHeaders', []);
        if (! is_array($headers)) {
            return [];
        }

        // GeoHub behaviour: exclude "errors" from template/export headers.
        $headers = array_filter($headers, static fn ($h) => (string) $h !== self::ERROR_COLUMN_NAME);

        return array_values(array_map(static fn ($h) => (string) $h, $headers));
    }

    /**
     * GeoHub-like taxonomy reference data for the 2nd sheet.
     *
     * @return array{languages: string[], poiTypes: array<int, array{id: int|string, identifier: string, names: array<string,string>}>, poiThemes: string[]}
     */
    public static function getTaxonomiesData(): array
    {
        // POI types: id + identifier + translated names (json array)
        $poiTypesData = DB::table('taxonomy_poi_types')
            ->select('id', 'identifier', 'name')
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($poiType) {
                $names = [];
                $nameArray = $poiType->name;
                if (is_string($nameArray)) {
                    $decoded = json_decode($nameArray, true);
                    $nameArray = is_array($decoded) ? $decoded : [];
                }
                if (is_array($nameArray)) {
                    foreach ($nameArray as $lang => $value) {
                        if (! empty($value)) {
                            $names[(string) $lang] = (string) $value;
                        }
                    }
                }

                return [
                    'id' => $poiType->id,
                    'identifier' => (string) ($poiType->identifier ?? ''),
                    'names' => $names,
                ];
            })
            ->toArray();

        // Collect languages from names, ordered consistently.
        $availableLanguages = [];
        foreach ($poiTypesData as $poiType) {
            $availableLanguages = array_merge($availableLanguages, array_keys($poiType['names'] ?? []));
        }
        $availableLanguages = array_values(array_unique($availableLanguages));

        $languageOrder = config('app.locales', ['it', 'en', 'fr', 'de', 'es', 'nl', 'sq']);
        $languageOrder = is_array($languageOrder) ? $languageOrder : ['it', 'en'];

        $sortedLanguages = [];
        foreach ($languageOrder as $lang) {
            if (in_array($lang, $availableLanguages, true)) {
                $sortedLanguages[] = $lang;
            }
        }
        $remaining = array_values(array_diff($availableLanguages, $sortedLanguages));
        sort($remaining);
        $sortedLanguages = array_values(array_merge($sortedLanguages, $remaining));

        // Themes: this project uses `slug` as identifier.
        // In GeoHub themes are restricted to user's apps; here we best-effort filter if apps relation exists.
        $poiThemes = [];
        $user = Auth::user();
        if ($user && method_exists($user, 'apps')) {
            try {
                foreach ($user->apps as $app) {
                    if (method_exists($app, 'taxonomyThemes')) {
                        $themes = $app->taxonomyThemes()->pluck('slug')->toArray();
                        $poiThemes = array_merge($poiThemes, $themes);
                    }
                }
            } catch (\Throwable $e) {
                $poiThemes = [];
            }
        }
        if ($poiThemes === []) {
            $poiThemes = TaxonomyTheme::query()->orderBy('slug')->pluck('slug')->toArray();
        }
        $poiThemes = array_values(array_unique(array_map('strval', $poiThemes)));

        return [
            'languages' => $sortedLanguages,
            'poiTypes' => $poiTypesData,
            'poiThemes' => $poiThemes,
        ];
    }

    /**
     * @param  string[]  $languages
     * @return string[]
     */
    public static function buildTaxonomiesSheetHeader(array $languages): array
    {
        $header = ['POI Type ID', 'Available POI Type Identifiers'];
        foreach ($languages as $lang) {
            $header[] = 'Available POI Type Names '.strtoupper($lang);
        }
        $header[] = 'Available POI Theme Identifiers';

        return $header;
    }

    /**
     * @param  array<int, array{id: int|string, identifier: string, names: array<string,string>}>  $poiTypes
     * @param  string[]  $poiThemes
     * @param  string[]  $languages
     * @return array<int, array<int, string>>
     */
    public static function buildTaxonomiesSheetRows(array $poiTypes, array $poiThemes, array $languages): array
    {
        $rows = [];

        $maxRows = max(count($poiTypes), count($poiThemes));
        for ($i = 0; $i < $maxRows; $i++) {
            $row = [];

            $poiTypeId = '';
            $poiTypeIdentifier = '';
            $poiTypeNames = [];
            if (isset($poiTypes[$i]) && is_array($poiTypes[$i])) {
                $poiTypeId = (string) ($poiTypes[$i]['id'] ?? '');
                $poiTypeIdentifier = (string) ($poiTypes[$i]['identifier'] ?? '');
                $poiTypeNames = is_array($poiTypes[$i]['names'] ?? null) ? $poiTypes[$i]['names'] : [];
            }

            $row[] = $poiTypeId;
            $row[] = $poiTypeIdentifier;
            foreach ($languages as $lang) {
                $row[] = (string) ($poiTypeNames[$lang] ?? '');
            }

            $row[] = (string) ($poiThemes[$i] ?? '');
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  string[]  $languages
     */
    public static function getTaxonomiesSheetColumnsCount(array $languages): int
    {
        // 2 (ID + Identifier) + languages count + 1 (Themes)
        return 2 + count($languages) + 1;
    }
}

