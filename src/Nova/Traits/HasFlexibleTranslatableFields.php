<?php

namespace Wm\WmPackage\Nova\Traits;

/**
 * Decodes translated values already persisted in Flexible layout attributes.
 *
 * Historically also produced an ad-hoc KeyValue-based translatable field
 * (translatableFields()) — superseded by Wm\WmPackage\Nova\Fields\FlexibleTranslatable
 * (oc:8349). decodeTranslatableValue() survives because it is read-only and
 * already handles both the legacy JSON-string format and the plain-array
 * format the new field produces.
 */
trait HasFlexibleTranslatableFields
{
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
