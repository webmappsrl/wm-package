<?php

namespace Wm\WmPackage\Exporters;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Export Excel per EcPoi. I taxonomy theme non sono inclusi (deprecati per i POI).
 *
 * Le intestazioni colonna provengono da {@see config('wm-excel-ec-import.ecPois.validHeaders')}.
 */
class EcPoiExcelExporter implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        private readonly Collection $pois
    ) {}

    public function collection(): Collection
    {
        return $this->pois->values();
    }

    public function headings(): array
    {
        return self::validHeaderNames();
    }

    /**
     * Elenco colonne come da config; se assente o vuota (es. bootstrap senza config) usa il default del pacchetto.
     *
     * @return list<string>
     */
    public static function validHeaderNames(): array
    {
        $headers = config('wm-excel-ec-import.ecPois.validHeaders');
        if (is_array($headers) && $headers !== []) {
            return array_values(array_map(static fn (mixed $h) => (string) $h, $headers));
        }

        return self::packagedDefaultValidHeaders();
    }

    /**
     * Stesso ordine della chiave wm-excel-ec-import.ecPois.validHeaders nel file di config del package.
     *
     * @return list<string>
     */
    private static function packagedDefaultValidHeaders(): array
    {
        return [
            'id',
            'name_it',
            'name_en',
            'description_it',
            'description_en',
            'excerpt_it',
            'excerpt_en',
            'poi_type',
            'lat',
            'lng',
            'addr_complete',
            'capacity',
            'contact_phone',
            'contact_email',
            'related_url',
            'feature_image',
            'gallery',
            'errors',
        ];
    }

    /**
     * @param  Model  $poi
     */
    public function map($poi): array
    {
        $id = data_get($poi, 'id');

        $name_it = $this->getTranslatable($poi, 'name', 'it');
        $name_en = $this->getTranslatable($poi, 'name', 'en');

        $description_it = $this->getTranslatable($poi, 'properties.description', 'it');
        $description_en = $this->getTranslatable($poi, 'properties.description', 'en');

        $excerpt_it = $this->getTranslatable($poi, 'properties.excerpt', 'it');
        $excerpt_en = $this->getTranslatable($poi, 'properties.excerpt', 'en');

        [$lat, $lng] = $this->extractLatLng($poi);

        $featureImage = $this->getFeatureImageUrl($poi);
        $gallery = $this->getGalleryUrls($poi);

        return [
            $id,
            $name_it,
            $name_en,
            $description_it,
            $description_en,
            $excerpt_it,
            $excerpt_en,
            $this->formatPoiTypeForExport($poi),
            $lat,
            $lng,
            data_get($poi, 'addr_complete') ?? data_get($poi, 'properties.addr_complete'),
            data_get($poi, 'capacity') ?? data_get($poi, 'properties.capacity'),
            data_get($poi, 'contact_phone') ?? data_get($poi, 'properties.contact_phone'),
            data_get($poi, 'contact_email') ?? data_get($poi, 'properties.contact_email'),
            $this->formatRelatedUrlForExport($poi),
            $featureImage,
            $gallery,
            '',
        ];
    }

    /**
     * URL leggibili in Excel: stringa semplice o valori di un array (es. related_url castato) separati da virgola.
     */
    private function formatRelatedUrlForExport(Model $poi): string
    {
        $raw = data_get($poi, 'related_url') ?? data_get($poi, 'properties.related_url');
        if (! $raw) {
            return '';
        }
        if (is_array($raw)) {
            return implode(',', array_values($raw));
        }

        return (string) $raw;
    }

    /**
     * Identificatori {@see TaxonomyPoiType} dalla pivot, fallback su properties.
     */
    private function formatPoiTypeForExport(Model $poi): string
    {
        if (method_exists($poi, 'taxonomyPoiTypes')) {
            try {
                if (! $poi->relationLoaded('taxonomyPoiTypes')) {
                    $poi->loadMissing('taxonomyPoiTypes');
                }
                $identifiers = $poi->taxonomyPoiTypes->pluck('identifier')->filter(static fn ($v) => $v !== null && $v !== '');
                if ($identifiers->isNotEmpty()) {
                    return $identifiers->unique()->values()->implode(',');
                }
            } catch (\Throwable $e) {
            }
        }

        return $this->stringify(data_get($poi, 'properties.poi_type'));
    }

    private function extractLatLng(Model $poi): array
    {
        try {
            $geojson = method_exists($poi, 'getGeojson') ? $poi->getGeojson() : null;
            $coords = data_get($geojson, 'geometry.coordinates');
            if (is_array($coords) && count($coords) >= 2) {
                $lng = $coords[0];
                $lat = $coords[1];

                return [$lat, $lng];
            }
        } catch (\Throwable $e) {
        }

        return ['', ''];
    }

    private function getFeatureImageUrl(Model $poi): string
    {
        try {
            if (method_exists($poi, 'getFirstMediaUrl')) {
                $url = (string) $poi->getFirstMediaUrl();
                if ($url !== '') {
                    return $url;
                }
            }
        } catch (\Throwable $e) {
        }

        return '';
    }

    private function getGalleryUrls(Model $poi): string
    {
        try {
            if (method_exists($poi, 'getMedia')) {
                $media = $poi->getMedia();
                $urls = [];
                foreach ($media as $m) {
                    if (method_exists($m, 'getUrl')) {
                        $urls[] = $m->getUrl();
                    }
                }

                return implode(',', array_filter($urls));
            }
        } catch (\Throwable $e) {
        }

        return '';
    }

    private function getTranslatable(Model $model, string $key, ?string $locale = null): string
    {
        $value = data_get($model, $key);

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            if ($locale !== null) {
                return (string) ($value[$locale] ?? '');
            }

            return $this->stringify($value);
        }

        if ($locale !== null && str_starts_with($key, 'properties.') === false) {
            try {
                if (method_exists($model, 'getTranslation')) {
                    return (string) $model->getTranslation($key, $locale, false);
                }
            } catch (\Throwable $e) {
            }
        }

        return $this->stringify($value);
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
    }
}
