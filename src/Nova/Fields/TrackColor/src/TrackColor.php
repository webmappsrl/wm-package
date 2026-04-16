<?php

namespace Wm\WmPackage\Nova\Fields\TrackColor\src;

use Illuminate\Support\Arr;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Traits\NormalizesHexColor;

class TrackColor extends Field
{
    use NormalizesHexColor;

    public $component = 'track-color';

    /**
     * Se true, quando il valore inviato coincide con il colore ereditato
     * viene salvato come null (chiave rimossa) invece che duplicato.
     */
    protected bool $normalizeToInherited = false;

    public function __construct($name = 'Color', $attribute = null, ?callable $resolveCallback = null)
    {
        // Default sensato: la colonna JSONB `properties` con chiave `color`.
        // Può essere sovrascritto, es. `style->color`, `colour`, `theme->line->color`, ecc.
        $attribute = $attribute ?? 'properties->color';

        parent::__construct($name, $attribute, $resolveCallback);
    }

    /**
     * Abilita la normalizzazione: se il valore custom coincide con l'ereditato
     * lo salva come null (chiave assente).
     */
    public function normalizeToInherited(bool $value = true): self
    {
        $this->normalizeToInherited = $value;

        return $this;
    }

    public function resolve($resource, ?string $attribute = null): void
    {
        parent::resolve($resource, $attribute);

        if (! $resource instanceof EcTrack) {
            return;
        }

        $attr = $attribute ?? $this->attribute;
        [$column, $path] = $this->parseAttribute($attr);

        $storedHex = $this->readStoredHex($resource, $column, $path);
        $inheritedHex = $resource->getInheritedTrackColorHex();
        $effectiveHex = $storedHex ?? $inheritedHex;

        $layers = $resource->getTrackLayersOrderedByRankDesc();
        $layer = $layers->first();
        $layerInfo = $layer ? [
            'id' => $layer->id,
            'name' => $layer->getStringName(),
        ] : null;

        $source = 'default';
        if ($storedHex !== null && $storedHex !== $inheritedHex) {
            $source = 'custom';
        } elseif ($layer) {
            $source = 'layer';
        }

        $this->withMeta([
            'effective_hex' => $effectiveHex ?? EcTrack::DEFAULT_COLOR_HEX,
            'inherited_hex' => $inheritedHex ?? EcTrack::DEFAULT_COLOR_HEX,
            'source' => $source,
            'layer' => $layerInfo,
            'stored_hex' => $storedHex,
            'attribute_path' => $attr,
            'debug' => app()->environment('local'),
        ]);
    }

    protected function fillAttributeFromRequest(NovaRequest $request, $requestAttribute, $model, $attribute): void
    {
        if (! $request->exists($requestAttribute)) {
            return;
        }

        $value = $request[$requestAttribute];
        [$column, $path] = $this->parseAttribute($attribute);

        $isReset = $value === '__RESET__' || $value === null || $value === '';

        if ($this->normalizeToInherited && ! $isReset && $model instanceof EcTrack) {
            $normalized = $this->normalizeHexColor((string) $value);
            $inherited = $model->getInheritedTrackColorHex();
            if ($normalized === $inherited) {
                $isReset = true;
            }
        }

        if ($column === null) {
            $model->{$attribute} = $isReset ? null : $value;
        } else {
            $current = $model->{$column};
            if (is_string($current)) {
                $decoded = json_decode($current, true);
                $current = is_array($decoded) ? $decoded : [];
            }
            if (! is_array($current)) {
                $current = [];
            }

            if ($isReset) {
                Arr::forget($current, $path);
            } else {
                Arr::set($current, $path, $value);
            }

            $model->{$column} = $current;
        }
    }

    /**
     * Interpreta l'attribute Nova (stile "column->path->sub") come
     * [colonna, dot-path]. Se l'attribute non contiene "->" la colonna è null
     * e il field scriverà su quell'attributo come scalare.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function parseAttribute(string $attribute): array
    {
        if (! str_contains($attribute, '->')) {
            return [null, null];
        }

        $parts = explode('->', $attribute);
        $column = array_shift($parts);
        $path = implode('.', $parts);

        return [$column, $path];
    }

    private function readStoredHex($model, ?string $column, ?string $path): ?string
    {
        if ($column === null) {
            $raw = $model->{$this->attribute} ?? null;
        } else {
            $container = $model->{$column};
            if (is_string($container)) {
                $decoded = json_decode($container, true);
                $container = is_array($decoded) ? $decoded : [];
            }
            if (! is_array($container)) {
                return null;
            }
            $raw = Arr::get($container, $path);
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return $this->normalizeHexColor($raw);
    }

    // normalizeHexColor estratto nel trait NormalizesHexColor
}
