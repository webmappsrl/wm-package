<?php

namespace Wm\WmPackage\Nova\Traits;

use Illuminate\Support\Facades\Config;
use Laravel\Nova\Fields\Text;

trait HasFlexibleTranslatableFields
{
    protected function translatableFields(string $label, string $attribute, bool $required = false): array
    {
        $languages = Config::get('wm-app-languages.languages', []);
        $fields = [];

        foreach ($languages as $locale => $name) {
            $field = Text::make("{$name}", "{$attribute}->{$locale}");
            if ($required && $locale === 'it') {
                $field->rules('required');
            }
            $fields[] = $field;
        }

        return $fields;
    }
}
