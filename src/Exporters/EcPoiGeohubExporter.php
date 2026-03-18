<?php

namespace Wm\WmPackage\Exporters;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class EcPoiGeohubExporter implements FromCollection, WithHeadings, WithMapping
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
        return [
            'id',
            'created_at',
            'updated_at',
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
            'theme',
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
            data_get($poi, 'created_at'),
            data_get($poi, 'updated_at'),
            $name_it,
            $name_en,
            $description_it,
            $description_en,
            $excerpt_it,
            $excerpt_en,
            $this->stringify(data_get($poi, 'properties.poi_type')),
            $lat,
            $lng,
            data_get($poi, 'addr_complete') ?? data_get($poi, 'properties.addr_complete'),
            data_get($poi, 'capacity') ?? data_get($poi, 'properties.capacity'),
            data_get($poi, 'contact_phone') ?? data_get($poi, 'properties.contact_phone'),
            data_get($poi, 'contact_email') ?? data_get($poi, 'properties.contact_email'),
            $this->stringify(data_get($poi, 'related_url') ?? data_get($poi, 'properties.related_url')),
            $featureImage,
            $gallery,
            $this->stringify(data_get($poi, 'properties.theme')),
            '',
        ];
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

