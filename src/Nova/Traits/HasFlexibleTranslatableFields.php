<?php

namespace Wm\WmPackage\Nova\Traits;

use Illuminate\Support\Facades\Config;
use Laravel\Nova\Fields\KeyValue;

trait HasFlexibleTranslatableFields
{
    protected function translatableFields(string $label, string $attribute, bool $required = false): array
    {
        $locales = Config::get('tab-translatable.locales', Config::get('wm-tab-translatable.locales', []));
        $default = array_fill_keys($locales, '');

        $field = KeyValue::make($label, $attribute)
            ->keyLabel('')
            ->valueLabel('Traduzione')
            ->disableAddingRows()
            ->disableDeletingRows()
            ->default($default)
            ->resolveUsing(function ($value) use ($default) {
                if (empty($value)) {
                    return $default;
                }

                return array_merge($default, is_array($value) ? $value : []);
            });

        if ($required) {
            $field->rules('required');
        }

        return [$field];
    }

    protected function decodeTranslatableValue(mixed $val): array
    {
        if (is_string($val)) {
            $val = json_decode($val, true);
        }

        if (! is_array($val)) {
            return [];
        }

        return array_filter($val, static fn ($v) => $v !== null && $v !== '');
    }
}
