<?php

namespace Wm\WmPackage\Tests\Unit\Nova\Traits;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Traits\HasFlexibleTranslatableFields;
use Wm\WmPackage\Tests\TestCase;

class HasFlexibleTranslatableFieldsTest extends TestCase
{
    private function makeField()
    {
        $host = new class
        {
            use HasFlexibleTranslatableFields;

            public function get()
            {
                return $this->translatableFields('Title', 'title', true);
            }
        };

        return $host->get()[0];
    }

    public function test_readonly_keys_is_enabled(): void
    {
        $field = $this->makeField();

        $this->assertTrue($field->readonlyKeys(NovaRequest::create('/', 'GET')));
    }

    public function test_resolve_strips_invalid_keys_leaving_configured_locales_empty(): void
    {
        $field = $this->makeField();

        $field->resolve(['title' => ['test poi track' => 'test poi track de']], 'title');

        $this->assertSame(['it' => '', 'en' => '', 'fr' => '', 'es' => '', 'de' => ''], $field->value);
    }

    public function test_resolve_preserves_valid_locales_and_drops_stray_key(): void
    {
        $field = $this->makeField();

        $field->resolve(['title' => ['it' => 'Titolo IT', 'en' => 'Title EN', 'stray key' => 'stray value']], 'title');

        $this->assertSame('Titolo IT', $field->value['it']);
        $this->assertSame('Title EN', $field->value['en']);
        $this->assertArrayNotHasKey('stray key', $field->value);
    }

    public function test_resolve_falls_back_to_default_when_value_is_empty(): void
    {
        $field = $this->makeField();

        $field->resolve(['title' => null], 'title');

        $this->assertSame(['it' => '', 'en' => '', 'fr' => '', 'es' => '', 'de' => ''], $field->value);
    }
}
