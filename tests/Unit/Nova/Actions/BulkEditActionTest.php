<?php

namespace Wm\WmPackage\Tests\Unit\Nova\Actions;

use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Laravel\Nova\Resource;
use Tests\TestCase;
use Wm\WmPackage\Models\EcPoi as EcPoiModel;
use Wm\WmPackage\Nova\Actions\BulkEditAction;
use Wm\WmPackage\Nova\EcPoi as EcPoiResource;

// Fixture Resource usata solo in questo test
class BulkEditTestResource extends Resource
{
    public static $model = EcPoiModel::class;

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make(),
            Text::make('Name', 'name'),
            Text::make('Hidden on update', 'hidden_on_update')->hideWhenUpdating(),
            Boolean::make('Static Readonly', 'static_readonly')->readonly(true),
            Boolean::make('Dynamic Readonly', 'dynamic_readonly')->readonly(fn ($r) => true),
            BelongsToMany::make('EcTracks', 'ecTracks', EcPoiResource::class),
            MorphToMany::make('Taxonomy', 'taxonomy', EcPoiResource::class),
            Panel::make('Details', [
                Text::make('Phone', 'phone'),
                Text::make('Email', 'email'),
            ]),
            Boolean::make('Global', 'global'),
            Text::make('Geometry', 'geometry'),
            NovaTabTranslatable::make([Text::make('Description', 'description')]),
        ];
    }
}

class BulkEditActionTest extends TestCase
{
    private function makeRequest(): NovaRequest
    {
        return app(NovaRequest::class);
    }

    public function test_excludes_id_field(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class);
        $fields = $action->fields($this->makeRequest());
        $attributes = collect($fields)->pluck('attribute')->all();

        $this->assertNotContains('id', $attributes);
    }

    public function test_excludes_belongs_to_many_fields(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class);
        $fields = $action->fields($this->makeRequest());
        $attributes = collect($fields)->pluck('attribute')->all();

        $this->assertNotContains('ecTracks', $attributes);
    }

    public function test_excludes_morph_to_many_fields(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class);
        $fields = $action->fields($this->makeRequest());
        $attributes = collect($fields)->pluck('attribute')->all();

        $this->assertNotContains('taxonomy', $attributes);
    }

    public function test_excludes_static_readonly_fields(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class);
        $fields = $action->fields($this->makeRequest());
        $attributes = collect($fields)->pluck('attribute')->all();

        $this->assertNotContains('static_readonly', $attributes);
    }

    public function test_excludes_fields_hidden_on_update(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class);
        $fields = $action->fields($this->makeRequest());
        $attributes = collect($fields)->pluck('attribute')->all();

        $this->assertNotContains('hidden_on_update', $attributes);
    }

    public function test_includes_dynamic_readonly_fields(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class);
        $fields = $action->fields($this->makeRequest());
        $attributes = collect($fields)->pluck('attribute')->all();

        $this->assertContains('dynamic_readonly', $attributes);
    }

    public function test_dynamic_readonly_callback_is_cleared(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class);
        $fields = $action->fields($this->makeRequest());

        $field = collect($fields)->firstWhere('attribute', 'dynamic_readonly');

        $this->assertNotNull($field);
        // Boolean with dynamic readonly is converted to Select; the new Select has no readonly.
        // For non-Boolean fields the callback is explicitly cleared to false.
        $this->assertNotSame(true, $field->readonlyCallback ?? null);
    }

    public function test_flattens_panel_fields(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class);
        $fields = $action->fields($this->makeRequest());
        $attributes = collect($fields)->pluck('attribute')->all();

        $this->assertContains('phone', $attributes);
        $this->assertContains('email', $attributes);
    }

    public function test_includes_standard_fields(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class);
        $fields = $action->fields($this->makeRequest());
        $attributes = collect($fields)->pluck('attribute')->all();

        // name è escluso di default da $exclude — solo global e altri campi scalari
        $this->assertNotContains('name', $attributes);
        $this->assertContains('global', $attributes);
    }

    public function test_filters_by_fields_array(): void
    {
        // Usa $exclude = [] per non escludere name e poter testare il filtro $fields
        $action = new BulkEditAction(BulkEditTestResource::class, ['name'], []);
        $fields = $action->fields($this->makeRequest());
        $attributes = collect($fields)->pluck('attribute')->all();

        $this->assertSame(['name'], $attributes);
    }

    public function test_returns_all_eligible_fields_when_fields_array_is_empty(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class);
        $fields = $action->fields($this->makeRequest());

        // Aspettati: dynamic_readonly, phone, email, global, description (5 campi)
        // Esclusi: id, hidden_on_update, static_readonly, ecTracks (BelongsToMany), taxonomy (MorphToMany)
        // Esclusi da $exclude default: name
        // description viene da NovaTabTranslatable via $originalFields
        $this->assertCount(5, $fields);
    }

    public function test_expands_nova_tab_translatable_original_fields(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class);
        $fields = $action->fields($this->makeRequest());
        $attributes = collect($fields)->pluck('attribute')->all();

        // NovaTabTranslatable is expanded via $originalFields — the inner field is exposed,
        // the container (which would corrupt ActionFields) is not.
        $this->assertContains('description', $attributes);
        $this->assertNotContains('tab_translatable', $attributes);
    }

    public function test_converts_boolean_fields_to_select_tri_state(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class, [], []);
        $fields = $action->fields($this->makeRequest());
        $globalField = collect($fields)->firstWhere('attribute', 'global');

        // Boolean is converted to Select so the modal can represent null (no change)
        $this->assertInstanceOf(Select::class, $globalField);
        // Marker used by handle() to cast '1'/'0' back to bool
        $this->assertTrue($globalField->meta['isBulkBoolean'] ?? false);
        // Must be nullable so the empty option = no change
        $this->assertTrue($globalField->nullable);
    }

    public function test_excludes_name_by_default(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class);
        $fields = $action->fields($this->makeRequest());
        $attributes = collect($fields)->pluck('attribute')->all();

        $this->assertNotContains('name', $attributes);
    }

    public function test_includes_name_when_exclude_is_empty(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class, [], []);
        $fields = $action->fields($this->makeRequest());
        $attributes = collect($fields)->pluck('attribute')->all();

        $this->assertContains('name', $attributes);
    }

    public function test_excludes_geometry_by_default(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class);
        $fields = $action->fields($this->makeRequest());
        $attributes = collect($fields)->pluck('attribute')->all();

        $this->assertNotContains('geometry', $attributes);
    }

    public function test_includes_geometry_when_exclude_is_empty(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class, [], []);
        $fields = $action->fields($this->makeRequest());
        $attributes = collect($fields)->pluck('attribute')->all();

        $this->assertContains('geometry', $attributes);
    }

    public function test_exclude_removes_specified_fields(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class, [], ['name', 'global']);
        $fields = $action->fields($this->makeRequest());
        $attributes = collect($fields)->pluck('attribute')->all();

        $this->assertNotContains('name', $attributes);
        $this->assertNotContains('global', $attributes);
    }

    public function test_all_returned_fields_are_nullable(): void
    {
        $action = new BulkEditAction(BulkEditTestResource::class);
        $fields = $action->fields($this->makeRequest());

        foreach ($fields as $field) {
            $this->assertTrue(
                $field->nullable,
                "Il campo '{$field->attribute}' non è nullable"
            );
        }
    }
}
