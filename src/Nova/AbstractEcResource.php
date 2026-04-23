<?php

namespace Wm\WmPackage\Nova;

use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Carbon\Carbon;
use Wm\WmPackage\Nova\Filters\FeaturesByLayerFilter;
use Wm\WmPackage\Services\StorageService;

abstract class AbstractEcResource extends AbstractGeometryResource
{
    /**
     * Build an "index" query for the given resource.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public static function indexQuery(NovaRequest $request, $query)
    {
        $user = Auth::user();

        if ($user && ! $user->hasRole('Administrator')) {
            $table = $query->getModel()->getTable();
            if (Schema::hasColumn($table, 'user_id')) {
                return $query->where('user_id', $user->id);
            }
        }

        return $query;
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            Images::make('Image', 'default'),
        ];
    }

    /**
     * Shared Accessibility fields for EC resources (EcPoi, EcTrack).
     *
     * These fields are stored as flat keys under the JSONB `properties` column
     * (e.g. `properties->access_mobility_check`), so the package can support them
     * without shipping migrations.
     *
     * The resulting API/GeoJSON still exposes them as flat keys in `properties`
     * (same external shape as GeoHub).
     *
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    protected function getAccessibilityTabFields(): array
    {
        return [
            $this->makePropertiesDateTimeField(__('Last verification date'), 'accessibility_validity_date'),
            File::make(__('Accessibility PDF'), 'properties->accessibility_pdf')
                ->acceptedTypes('.pdf')
                ->rules('mimes:pdf')
                ->hideFromIndex()
                ->deletable()
                ->store(function ($request, $model, $attribute, $requestAttribute) {
                    $file = $request->file($requestAttribute);
                    if ($file === null) {
                        return null;
                    }

                    return app(StorageService::class)->storeFile($model, 'accessibility', $file);
                })
                ->delete(function ($request, $model) {
                    app(StorageService::class)->deleteFile($model, 'accessibility');
                    $properties = is_array($model->properties) ? $model->properties : [];
                    unset($properties['accessibility_pdf']);
                    $model->properties = $properties;
                }),

            $this->makePropertiesBooleanCheckField(__('Access Mobility Check'), 'access_mobility_check'),
            Select::make(__('Access Mobility Level'), 'properties->access_mobility_level')->options([
                'accessible_independently' => 'Accessible independently',
                'accessible_with_assistance' => 'Accessible with assistance',
                'accessible_with_a_guide' => 'Accessible with a guide',
            ])->nullable()->hideFromIndex(),
            Textarea::make(__('Access Mobility Description'), 'properties->access_mobility_description')->hideFromIndex(),

            $this->makePropertiesBooleanCheckField(__('Access Hearing Check'), 'access_hearing_check'),
            Select::make(__('Access Hearing Level'), 'properties->access_hearing_level')->options([
                'accessible_independently' => 'Accessible independently',
                'accessible_with_assistance' => 'Accessible with assistance',
                'accessible_with_a_guide' => 'Accessible with a guide',
            ])->nullable()->hideFromIndex(),
            Textarea::make(__('Access Hearing Description'), 'properties->access_hearing_description')->hideFromIndex(),

            $this->makePropertiesBooleanCheckField(__('Access Vision Check'), 'access_vision_check'),
            Select::make(__('Access Vision Level'), 'properties->access_vision_level')->options([
                'accessible_independently' => 'Accessible independently',
                'accessible_with_assistance' => 'Accessible with assistance',
                'accessible_with_a_guide' => 'Accessible with a guide',
            ])->nullable()->hideFromIndex(),
            Textarea::make(__('Access Vision Description'), 'properties->access_vision_description')->hideFromIndex(),

            $this->makePropertiesBooleanCheckField(__('Access Cognitive Check'), 'access_cognitive_check'),
            Select::make(__('Access Cognitive Level'), 'properties->access_cognitive_level')->options([
                'accessible_independently' => 'Accessible independently',
                'accessible_with_assistance' => 'Accessible with assistance',
                'accessible_with_a_guide' => 'Accessible with a guide',
            ])->nullable()->hideFromIndex(),
            Textarea::make(__('Access Cognitive Description'), 'properties->access_cognitive_description')->hideFromIndex(),

            $this->makePropertiesBooleanCheckField(__('Access Food Check'), 'access_food_check'),
            Textarea::make(__('Access Food Description'), 'properties->access_food_description')->hideFromIndex(),
        ];
    }

    /**
     * Nova DateTime needs a DateTimeInterface when resolving. When using JSON paths
     * like `properties->foo`, Nova resolves the raw string from the array and
     * Eloquent casts are bypassed. This helper resolves/fills through `properties`.
     */
    protected function makePropertiesDateTimeField(string $name, string $key): Field
    {
        return DateTime::make($name, $key)
            ->resolveUsing(function ($value, $resource) use ($key) {
                $raw = data_get($resource->properties ?? [], $key);
                if (empty($raw)) {
                    return null;
                }

                try {
                    return Carbon::parse($raw);
                } catch (\Throwable $e) {
                    return null;
                }
            })
            ->fillUsing(function ($request, $model, $attribute, $requestAttribute) use ($key) {
                $properties = is_array($model->properties) ? $model->properties : [];
                $incoming = $request->get($requestAttribute);

                if ($incoming === null || $incoming === '') {
                    unset($properties[$key]);
                } else {
                    $properties[$key] = $incoming;
                }

                $model->properties = $properties;
            })
            ->hideFromIndex();
    }

    /**
     * Store boolean "check" flags in properties only when true.
     * This matches GeoHub's external behavior (omit falsy checks).
     */
    protected function makePropertiesBooleanCheckField(string $name, string $key): Field
    {
        return Boolean::make($name, $key)
            ->resolveUsing(function ($value, $resource) use ($key) {
                $raw = data_get($resource->properties ?? [], $key);
                return $raw ? true : false;
            })
            ->fillUsing(function ($request, $model, $attribute, $requestAttribute) use ($key) {
                $properties = is_array($model->properties) ? $model->properties : [];
                $incoming = $request->get($requestAttribute);

                // Nova sends booleans as true/false; be defensive for 0/"0"/"false".
                $isTrue = $incoming === true || $incoming === 1 || $incoming === '1' || $incoming === 'true' || $incoming === 'on';

                if ($isTrue) {
                    $properties[$key] = true;
                } else {
                    unset($properties[$key]);
                }

                $model->properties = $properties;
            })
            ->hideFromIndex();
    }

    /**
     * Shared "Details" tabs builder to avoid duplicating tabs across EC resources.
     *
     * @param  array<int, \Laravel\Nova\Fields\Field>  $infoFields
     * @return array<int, \Laravel\Nova\Fields\Field|\Laravel\Nova\Tabs\Tabs>
     */
    protected function makeDetailsTabs(array $infoFields): array
    {
        $accessibility = $this->getAccessibilityTabFields();

        return [
            Tab::group(__('Details'), array_filter([
                Tab::make(__('Info'), $infoFields),
                $accessibility ? Tab::make(__('Accessibility'), $accessibility) : null,
            ])),
        ];
    }

    public function filters(NovaRequest $request): array
    {
        return [
            ...parent::filters($request),
            new FeaturesByLayerFilter($this->model()::class),
        ];
    }

    /**
     * Determine if this resource uses Laravel Scout.
     * https://nova.laravel.com/docs/v5/search/scout-integration
     *
     * @return bool
     */
    public static function usesScout()
    {
        return false;
    }
}
