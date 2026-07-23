<?php

namespace Wm\WmPackage\Nova\Fields\TranslationsBuilder;

use Illuminate\Database\Eloquent\Model;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Support\Fluent;

class TranslationsBuilder extends Field
{
    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'translations-builder';

    /**
     * Set the languages managed by this field.
     *
     * @return $this
     */
    public function langs(array $langs = ['it'])
    {
        return $this->withMeta(['langs' => empty($langs) ? ['it'] : $langs]);
    }

    /**
     * Resolve the field's value.
     *
     * @param  Model|object  $resource
     */
    public function resolve($resource, ?string $attribute = null): void
    {
        $langs = $this->meta['langs'] ?? ['it'];

        $values = [];
        foreach ($langs as $lang) {
            $values[$lang] = $resource->{"translations_{$lang}"} ?? [];
        }

        $this->value = [
            'langs' => $langs,
            'values' => $values,
        ];
    }

    /**
     * Hydrate the given attribute on the model based on the incoming request.
     *
     * @param  Model|Fluent  $model
     */
    protected function fillAttributeFromRequest(NovaRequest $request, string $requestAttribute, object $model, string $attribute): void
    {
        if (! $request->exists($requestAttribute)) {
            return;
        }

        $raw = $request[$requestAttribute];
        if (! is_string($raw)) {
            return;
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return;
        }

        $langs = $this->meta['langs'] ?? ['it'];

        $fill = [];
        foreach ($langs as $lang) {
            if (array_key_exists($lang, $payload) && is_array($payload[$lang])) {
                $fill["translations_{$lang}"] = $payload[$lang];
            }
        }

        $model->forceFill($fill);
    }
}
