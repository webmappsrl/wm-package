<?php

namespace Wm\WmPackage\Nova\Flexible\ConfigHome;

use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Repeater\Repeatable;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcPoi as EcPoiModel;
use Wm\WmPackage\Models\EcTrack as EcTrackModel;
use Wm\WmPackage\Nova\Fields\PoiTrackReferenceField\src\PoiTrackReferenceField;

/**
 * Repeatable block for the `horizontal_scroll_poi_track` layout on `config_home`. Uses the custom
 * `PoiTrackReferenceField` (Model toggle Poi/Track + a single search filtered client-side) instead of two
 * always-visible Select fields — `dependsOn()` does not work for fields nested this deep inside a
 * Flexible > Repeater > Repeatable (verified reading `vendor/laravel/nova/src/Http/Controllers/UpdateFieldController.php`),
 * so the conditional filtering the ticket asks for had to be built as a standalone Vue component instead.
 *
 * The `title` field is read-only: the title always comes from the linked Poi/Track (`ConfigHomeResolver::
 * mergeItemTitle()` already inherits it at save time when absent from the payload), it is never editable
 * from the builder.
 */
class HorizontalScrollPoiTrackItemRepeatable extends Repeatable
{
    public static function key(): string
    {
        return 'horizontal-scroll-poi-track-item';
    }

    /**
     * Overridden so the Nova "Add" button reuses the same label already shown for this box in the
     * Flexible layout picker, instead of Nova's default (humanized class name).
     */
    public static function label(): string
    {
        return __('Horizontal Scroll Poi/Track');
    }

    public static function singularLabel(): string
    {
        return __('Horizontal Scroll Poi/Track');
    }

    /**
     * @return array<int, Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            PoiTrackReferenceField::make(__('Model'), 'model_ref')
                ->poiOptions($this->modelOptions(EcPoiModel::class, $request->resourceId))
                ->trackOptions($this->modelOptions(EcTrackModel::class, $request->resourceId))
                ->help(__('Choose Poi or Track, then search among that model\'s records.'))
                ->rules('required'),

            Text::make(__('Title'), 'title')
                ->readonly()
                ->resolveUsing(fn ($value, $resource) => $this->resolveInheritedTitle($resource))
                ->help(__('Automatically inherited from the linked Poi/Track. Read-only, shown after the item has been saved.')),

            Text::make(__('Image URL'), 'image_url')
                ->nullable()
                ->help(__('Leave empty to inherit the image from the linked Poi/Track, if it has one.')),
        ];
    }

    /**
     * @param  mixed  $resource
     */
    private function resolveInheritedTitle($resource): string
    {
        $data = is_array($resource) ? $resource : (array) $resource;

        $poiId = $data['poi_id'] ?? null;
        $trackId = $data['track_id'] ?? null;

        if (! empty($poiId)) {
            $poi = EcPoiModel::query()->find($poiId);

            return $poi ? $this->cascadeTranslation($poi->getTranslations('name')) : '';
        }

        if (! empty($trackId)) {
            $track = EcTrackModel::query()->find($trackId);

            return $track ? $this->cascadeTranslation($track->getTranslations('name')) : '';
        }

        return '';
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
                return [$model->id => $this->modelLabel($model->getTranslations('name'), $model->id)];
            })
            ->sort()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $translations
     */
    private function modelLabel(array $translations, int $fallbackId): string
    {
        $cascaded = $this->cascadeTranslation($translations);

        return $cascaded !== '' ? $cascaded : '#'.$fallbackId;
    }

    /**
     * Cascade `it` -> `en` -> first non-empty translation, empty string if none is set.
     *
     * @param  array<string, mixed>  $translations
     */
    private function cascadeTranslation(array $translations): string
    {
        foreach (['it', 'en'] as $locale) {
            $value = trim((string) ($translations[$locale] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        foreach ($translations as $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
