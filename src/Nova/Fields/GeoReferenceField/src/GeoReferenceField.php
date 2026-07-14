<?php

namespace Wm\WmPackage\Nova\Fields\GeoReferenceField\src;

use Illuminate\Database\Eloquent\Model;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Support\Fluent;

/**
 * A single field that lets the admin pick a "Model" (Poi/Track) and then search among that model's
 * records, writing the result into two sibling attributes (`poi_id`/`track_id`) on the underlying Fluent —
 * exactly mirroring the shape `ConfigHomeResolver::fromGeoRepeaterItems()` already expects. The filtering
 * happens entirely client-side (both option lists are preloaded), since Nova's native `dependsOn()` field
 * sync does not reach fields nested this deep inside a Flexible > Repeater > Repeatable (verified reading
 * `vendor/laravel/nova/src/Http/Controllers/UpdateFieldController.php`).
 */
class GeoReferenceField extends Field
{
    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'geo-reference-field';

    /**
     * @param  array<int, string>  $options  id => label
     */
    public function poiOptions(array $options): static
    {
        return $this->withMeta(['poiOptions' => $options]);
    }

    /**
     * @param  array<int, string>  $options  id => label
     */
    public function trackOptions(array $options): static
    {
        return $this->withMeta(['trackOptions' => $options]);
    }

    /**
     * @param  mixed  $resource
     * @return array{type: string|null, id: int|null}
     */
    protected function resolveAttribute($resource, string $attribute): mixed
    {
        $data = is_array($resource) ? $resource : (array) $resource;

        $poiId = $data['poi_id'] ?? null;
        $trackId = $data['track_id'] ?? null;

        if (! empty($trackId)) {
            return ['type' => 'track', 'id' => (int) $trackId];
        }

        if (! empty($poiId)) {
            return ['type' => 'poi', 'id' => (int) $poiId];
        }

        return ['type' => null, 'id' => null];
    }

    /**
     * @param  Model|Fluent  $model
     */
    protected function fillAttributeFromRequest(NovaRequest $request, string $requestAttribute, object $model, string $attribute): void
    {
        if (! $request->exists($requestAttribute)) {
            return;
        }

        $raw = $request->input($requestAttribute);
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        $type = is_array($decoded) ? ($decoded['type'] ?? null) : null;
        $id = is_array($decoded) ? ($decoded['id'] ?? null) : null;

        $model->forceFill([
            'poi_id' => $type === 'poi' && $id ? (int) $id : null,
            'track_id' => $type === 'track' && $id ? (int) $id : null,
        ]);
    }
}
