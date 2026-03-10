<?php

namespace Wm\WmPackage\Exporters;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class EcTrackExcelExporter implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        private readonly Collection $tracks
    ) {}

    public function collection(): Collection
    {
        return $this->tracks->values();
    }

    public function headings(): array
    {
        return [
            'id',
            'created_at',
            'updated_at',
            'osmid',
            'name',
            'name_it',
            'name_en',
            'name_fr',
            'geohub_backend',
            'geohub_backend_edit',
            'geohub_frontend',
            'public_app_link',
            'description',
            'description_it',
            'description_en',
            'description_fr',
            'excerpt',
            'source',
            'distance_comp',
            'user_id',
            'feature_image',
            'audio',
            'distance',
            'ascent',
            'descent',
            'ele_from',
            'ele_to',
            'ele_min',
            'ele_max',
            'duration_forward',
            'duration_backward',
            'difficulty',
            'slope',
            'mbtiles',
            'elevation_chart_image',
            'out_source_feature_id',
            'from',
            'ref',
            'to',
            'cai_scale',
            'related_url',
            'not_accessible',
            'not_accessible_message',
            'image_gallery',
            'activity',
            'where',
            'when',
            'target',
        ];
    }

    /**
     * @param  Model  $track
     */
    public function map($track): array
    {
        $id = data_get($track, 'id');

        $name = $this->getTranslatable($track, 'name');
        $name_it = $this->getTranslatable($track, 'name', 'it');
        $name_en = $this->getTranslatable($track, 'name', 'en');
        $name_fr = $this->getTranslatable($track, 'name', 'fr');

        $description = $this->getTranslatable($track, 'properties.description');
        $description_it = $this->getTranslatable($track, 'properties.description', 'it');
        $description_en = $this->getTranslatable($track, 'properties.description', 'en');
        $description_fr = $this->getTranslatable($track, 'properties.description', 'fr');

        $excerpt = $this->getTranslatable($track, 'properties.excerpt');

        $geohub_backend = url('/').'/resources/ec-tracks/'.$id;
        $geohub_frontend = url('/').'/track/'.$id;
        $geohub_backend_edit = 'https://geohub.webmapp.it/resources/ec-tracks/'.$id.'/edit';

        // In questo progetto il concetto di "public_app_link" come in Geohub
        // non è garantito (e la relazione apps sull'utente può non esistere
        // o fallire dentro una transazione fallita). Per evitare di rompere
        // l'export, lasciamo il campo vuoto.
        $public_app_link = '';

        $featureImage = $this->getFeatureImageUrl($track);
        $image_gallery = $this->getGalleryUrls($track);

        $activities = $this->pluckRelationNames($track, 'taxonomyActivities');
        $wheres = $this->pluckRelationNames($track, 'taxonomyWheres');
        $whens = $this->pluckRelationNames($track, 'taxonomyWhens');
        $targets = $this->pluckRelationNames($track, 'taxonomyTargets');

        return [
            $id,
            data_get($track, 'created_at'),
            data_get($track, 'updated_at'),
            data_get($track, 'osmid'),
            $name,
            $name_it,
            $name_en,
            $name_fr,
            $geohub_backend,
            $geohub_backend_edit,
            // $geohub_frontend, TODO: esiste su geohub non attualmente su wmpackage esempio: https://geohub.webmapp.it/track/89727
            $public_app_link,
            $description,
            $description_it,
            $description_en,
            $description_fr,
            $excerpt,
            data_get($track, 'properties.source'),
            data_get($track, 'properties.distance_comp'),
            data_get($track, 'user_id'),
            $featureImage,
            $this->getTranslatable($track, 'properties.audio'),
            data_get($track, 'properties.distance'),
            data_get($track, 'properties.ascent'),
            data_get($track, 'properties.descent'),
            data_get($track, 'properties.ele_from'),
            data_get($track, 'properties.ele_to'),
            data_get($track, 'properties.ele_min'),
            data_get($track, 'properties.ele_max'),
            data_get($track, 'properties.duration_forward'),
            data_get($track, 'properties.duration_backward'),
            $this->getTranslatable($track, 'properties.difficulty'),
            data_get($track, 'properties.slope'),
            data_get($track, 'properties.mbtiles'),
            data_get($track, 'properties.elevation_chart_image'),
            data_get($track, 'properties.out_source_feature_id') ?? data_get($track, 'out_source_feature_id'),
            data_get($track, 'properties.from'),
            data_get($track, 'properties.ref'),
            data_get($track, 'properties.to'),
            data_get($track, 'properties.cai_scale'),
            $this->stringify(data_get($track, 'properties.related_url')),
            data_get($track, 'properties.not_accessible'),
            data_get($track, 'properties.not_accessible_message'),
            $image_gallery,
            $activities,
            $wheres,
            $whens,
            $targets,
        ];
    }

    private function getFeatureImageUrl(Model $track): string
    {
        try {
            if (method_exists($track, 'getFirstMediaUrl')) {
                $url = (string) $track->getFirstMediaUrl();
                if ($url !== '') {
                    return $url;
                }
            }
        } catch (\Throwable $e) {
        }

        return '';
    }

    private function getGalleryUrls(Model $track): string
    {
        try {
            if (method_exists($track, 'getMedia')) {
                $media = $track->getMedia();
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

    private function pluckRelationNames(Model $model, string $relation): string
    {
        try {
            if (! method_exists($model, $relation)) {
                return '';
            }

            $relationQuery = $model->{$relation}();
            $related = $relationQuery->getRelated();
            if ($related instanceof Model && ! Schema::hasTable($related->getTable())) {
                return '';
            }

            $items = $model->getRelationValue($relation);
            if (! $items) {
                $items = $model->{$relation}()->get();
            }

            if (! $items instanceof \Illuminate\Support\Collection) {
                $items = collect($items);
            }

            $names = $items->map(function ($item) {
                if (! $item instanceof Model) {
                    return null;
                }

                return $this->getTranslatable($item, 'name', 'it') ?: $this->stringify(data_get($item, 'name'));
            })->filter()->values()->all();

            return implode(',', $names);
        } catch (\Throwable $e) {
            return '';
        }
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

        // Support Spatie translatable for top-level attributes
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

