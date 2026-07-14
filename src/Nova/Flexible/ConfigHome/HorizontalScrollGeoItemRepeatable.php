<?php

namespace Wm\WmPackage\Nova\Flexible\ConfigHome;

use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Repeater\Repeatable;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcPoi as EcPoiModel;
use Wm\WmPackage\Models\EcTrack as EcTrackModel;
use Wm\WmPackage\Nova\Fields\GeoReferenceField\src\GeoReferenceField;
use Wm\WmPackage\Nova\Traits\HasFlexibleTranslatableFields;

/**
 * Repeatable block for the `horizontal_scroll_geo` layout on `config_home`. Uses the custom
 * `GeoReferenceField` (Model toggle Poi/Track + a single search filtered client-side) instead of two
 * always-visible Select fields — `dependsOn()` does not work for fields nested this deep inside a
 * Flexible > Repeater > Repeatable (verified reading `vendor/laravel/nova/src/Http/Controllers/UpdateFieldController.php`),
 * so the conditional filtering the ticket asks for had to be built as a standalone Vue component instead.
 */
class HorizontalScrollGeoItemRepeatable extends Repeatable
{
    use HasFlexibleTranslatableFields;

    public static function key(): string
    {
        return 'horizontal-scroll-geo-item';
    }

    /**
     * @return array<int, Field>
     */
    public function fields(NovaRequest $request): array
    {
        $fields = [
            GeoReferenceField::make(__('Reference'), 'geo_ref')
                ->poiOptions($this->modelOptions(EcPoiModel::class, $request->resourceId))
                ->trackOptions($this->modelOptions(EcTrackModel::class, $request->resourceId))
                ->help(__('Choose Poi or Track, then search among that model\'s records.')),
        ];

        foreach ($this->translatableFields(__('Title'), 'title') as $field) {
            $fields[] = $field->nullable()
                ->help(__('Leave empty to inherit the name from the linked Poi/Track.'));
        }

        $fields[] = Text::make(__('Image URL'), 'image_url')
            ->nullable()
            ->help(__('Leave empty to inherit the image from the linked Poi/Track, if it has one.'));

        return $fields;
    }

    /**
     * Options map (id => translatable name) for the given model class, scoped to the current App.
     *
     * @param  class-string<EcPoiModel|EcTrackModel>  $modelClass
     * @return array<int, string>
     */
    private function modelOptions(string $modelClass, mixed $appId): array
    {
        if (empty($appId)) {
            return [];
        }

        return $modelClass::query()
            ->where('app_id', $appId)
            ->get(['id', 'name'])
            ->mapWithKeys(function ($model) {
                return [$model->id => $this->modelLabel($model->name, $model->id)];
            })
            ->sort()
            ->all();
    }

    /**
     * @param  mixed  $name
     */
    private function modelLabel($name, int $fallbackId): string
    {
        if (is_array($name)) {
            return (string) ($name['it'] ?? $name['en'] ?? ('#'.$fallbackId));
        }

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return '#'.$fallbackId;
    }
}
