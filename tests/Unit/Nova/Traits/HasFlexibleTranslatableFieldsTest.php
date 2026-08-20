<?php

namespace Wm\WmPackage\Tests\Unit\Nova\Traits;

use Wm\WmPackage\Nova\Traits\HasFlexibleTranslatableFields;
use Wm\WmPackage\Tests\TestCase;

/**
 * translatableFields() (the old KeyValue-based translatable field) was removed by
 * oc:8349, superseded by Wm\WmPackage\Nova\Fields\FlexibleTranslatable — this test
 * used to exercise that removed method (found stale in review). decodeTranslatableValue()
 * is the only method left in the trait, still used in read by 3 Flexible resolvers.
 */
class HasFlexibleTranslatableFieldsTest extends TestCase
{
    private function decode(mixed $value): array
    {
        $host = new class
        {
            use HasFlexibleTranslatableFields;

            public function decode(mixed $value): array
            {
                return $this->decodeTranslatableValue($value);
            }
        };

        return $host->decode($value);
    }

    public function test_decodes_a_json_encoded_string_into_an_array(): void
    {
        $result = $this->decode(json_encode(['it' => 'Ciao', 'en' => 'Hello']));

        $this->assertSame(['it' => 'Ciao', 'en' => 'Hello'], $result);
    }

    public function test_passes_through_an_already_decoded_array(): void
    {
        $result = $this->decode(['it' => 'Ciao', 'en' => 'Hello']);

        $this->assertSame(['it' => 'Ciao', 'en' => 'Hello'], $result);
    }

    public function test_filters_out_null_and_empty_string_values_but_keeps_every_other_key(): void
    {
        $result = $this->decode(['it' => 'Ciao', 'en' => '', 'fr' => null, 'stray key' => 'stray value']);

        $this->assertSame(['it' => 'Ciao', 'stray key' => 'stray value'], $result);
    }

    public function test_returns_an_empty_array_for_non_array_non_decodable_input(): void
    {
        $this->assertSame([], $this->decode(null));
        $this->assertSame([], $this->decode('not valid json'));
        $this->assertSame([], $this->decode(42));
    }
}
