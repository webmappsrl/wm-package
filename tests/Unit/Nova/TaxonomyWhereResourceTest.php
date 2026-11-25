<?php

namespace Wm\WmPackage\Tests\Unit\Nova;

use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;
use Wm\WmPackage\Nova\TaxonomyWhere as NovaTaxonomyWhere;
use Wm\WmPackage\Tests\TestCase;

/**
 * Il nome di una TaxonomyWhere è tradotto (Spatie): in Nova va modificato per lingua, non solo
 * nella lingua di `app.locale` (oc:8958).
 */
class TaxonomyWhereResourceTest extends TestCase
{
    /**
     * Campi della Resource su una TaxonomyWhere nuova.
     *
     * @return array<int, mixed>
     */
    private function fields(): array
    {
        $resource = new NovaTaxonomyWhere(new TaxonomyWhere);

        return $resource->fields(NovaRequest::create('/'));
    }

    public function test_name_is_editable_per_language(): void
    {
        $translatable = collect($this->fields())
            ->first(fn ($field) => $field instanceof NovaTabTranslatable);

        $this->assertNotNull($translatable);

        $nameField = $translatable->originalFields[0];
        $this->assertInstanceOf(Text::class, $nameField);
        $this->assertSame('name', $nameField->attribute);
    }

    public function test_other_fields_are_unchanged(): void
    {
        $fields = collect($this->fields());

        $this->assertTrue($fields->contains(fn ($field) => $field instanceof ID));
        $this->assertTrue($fields->contains(
            fn ($field) => $field instanceof FeatureCollectionMap && $field->attribute === 'geometry'
        ));
        $this->assertFalse($fields->contains(
            fn ($field) => $field instanceof Text && $field->attribute === 'name'
        ));
    }
}
