<?php

namespace Wm\WmPackage\Tests\Feature\Nova\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\ActionRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Mockery;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Actions\ReverseTrackDirectionAction;
use Wm\WmPackage\Nova\EcTrack as EcTrackResource;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Tests\TestCase;

class ReverseTrackDirectionActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        config(['scout.driver' => 'null']);
    }

    private function track(array $properties = [], string $wkt = 'MULTILINESTRING Z((0 0 0, 1 1 100, 2 2 200))'): EcTrack
    {
        // osmid esplicito: la factory lo valorizza a caso nel 70% dei casi, e una traccia OSM
        // è in sola lettura per l'inversione.
        return EcTrack::factory()->createQuietly([
            'geometry' => DB::raw("ST_GeomFromText('{$wkt}', 4326)"),
            'properties' => $properties,
            'osmid' => null,
        ]);
    }

    private function wkt(EcTrack $track): string
    {
        return DB::selectOne('SELECT ST_AsText(geometry::geometry) AS wkt FROM ec_tracks WHERE id = ?', [$track->id])->wkt;
    }

    private function runAction(EcTrack $track, array $values)
    {
        return DB::transaction(fn () => (new ReverseTrackDirectionAction)->handle(
            new ActionFields(collect($values), collect()),
            collect([$track])
        ));
    }

    private function fieldsFor(?EcTrack $track, $selection = 'one'): array
    {
        $request = Mockery::mock(NovaRequest::class)->makePartial();
        $request->shouldReceive('selectedResources')->andReturn(match ($selection) {
            'one' => collect([$track]),
            'none' => collect(),
            'all' => null,
        });

        return (new ReverseTrackDirectionAction)->fields($request);
    }

    /**
     * canSee()/canRun() sono agganciati in EcTrack::actions(): vanno risolti dalla Resource,
     * non con `new ReverseTrackDirectionAction` (oc:8569).
     */
    private function resolveAction(NovaRequest $request): ReverseTrackDirectionAction
    {
        return collect((new EcTrackResource(new EcTrack))->actions($request))
            ->first(fn ($action) => $action instanceof ReverseTrackDirectionAction);
    }

    public function test_fields_show_the_geometry_flag_and_one_flag_per_valued_pair()
    {
        $fields = $this->fieldsFor($this->track(['manual_data' => ['ascent' => 500], 'from' => 'A', 'to' => 'B']));

        $this->assertSame(
            ['reverse_geometry', 'swap_ascent_descent', 'swap_from_to'],
            array_map(fn ($field) => $field->attribute, $fields)
        );
        // Boolean::resolveDefaultValue() restituisce il default solo in una richiesta di Action.
        $actionRequest = ActionRequest::create('/');
        $this->assertTrue($fields[0]->resolveDefaultValue($actionRequest));
        $this->assertFalse($fields[1]->resolveDefaultValue($actionRequest));
        $this->assertStringContainsString('500', $fields[1]->helpText);
        $this->assertStringContainsString('—', $fields[1]->helpText);
    }

    public function test_fields_without_selection()
    {
        foreach (['none', 'all'] as $selection) {
            $fields = $this->fieldsFor(null, $selection);
            $this->assertSame(['reverse_geometry'], array_map(fn ($field) => $field->attribute, $fields));
        }
    }

    public function test_help_shows_non_scalar_values()
    {
        $fields = $this->fieldsFor($this->track(['from' => ['it' => 'Partenza', 'en' => 'Start']]));

        $this->assertStringContainsString('Partenza', $fields[1]->helpText);
    }

    public function test_help_escapes_html_coming_from_the_data()
    {
        // Nova rende l'help con v-html: un valore importato non deve diventare markup (oc:8543).
        $fields = $this->fieldsFor($this->track(['from' => '<b>x</b>']));

        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $fields[1]->helpText);
        $this->assertStringNotContainsString('<b>', $fields[1]->helpText);
    }

    public function test_all_flags_off_is_an_error_and_writes_nothing()
    {
        $track = $this->track(['manual_data' => ['ascent' => 500]]);
        $before = $this->wkt($track);

        $result = $this->runAction($track, ['reverse_geometry' => false, 'swap_ascent_descent' => false]);

        $this->assertArrayHasKey('danger', $result);
        $this->assertSame($before, $this->wkt($track));
        Bus::assertNothingDispatched();
    }

    public function test_osm_track_is_an_error_and_writes_nothing()
    {
        $track = $this->track(['osmid' => 123]);
        $before = $this->wkt($track);

        $result = $this->runAction($track, ['reverse_geometry' => true]);

        $this->assertArrayHasKey('danger', $result);
        $this->assertSame($before, $this->wkt($track));
        Bus::assertNothingDispatched();
    }

    public function test_it_reverses_a_multipart_geometry_including_the_order_of_the_parts()
    {
        $track = $this->track([], 'MULTILINESTRING Z((0 0 0, 1 1 100),(10 10 200, 11 11 300))');

        $this->runAction($track, ['reverse_geometry' => true]);

        $this->assertSame('MULTILINESTRING Z ((11 11 300,10 10 200),(1 1 100,0 0 0))', $this->wkt($track));
    }

    public function test_final_message_says_what_was_done()
    {
        app()->setLocale('en');
        $track = $this->track(['manual_data' => ['ascent' => 500, 'descent' => 300]]);

        $result = $this->runAction($track, ['reverse_geometry' => false, 'swap_ascent_descent' => true]);

        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('Geometry reversed: No', $result['message']);
        $this->assertStringContainsString('Ascent / Descent', $result['message']);
    }

    public function test_administrator_can_see_and_run_the_action()
    {
        RolesAndPermissionsService::seedDatabase();
        $administrator = User::factory()->create();
        $administrator->assignRole('Administrator');
        Auth::login($administrator);
        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $administrator);

        $action = $this->resolveAction($request);

        $this->assertTrue($action->authorizedToSee($request));
        $this->assertTrue($action->authorizedToRun($request, $this->track()));
    }

    public function test_editor_cannot_see_or_run_the_action()
    {
        RolesAndPermissionsService::seedDatabase();
        $editor = User::factory()->create();
        $editor->assignRole('Editor');
        Auth::login($editor);
        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $editor);

        $action = $this->resolveAction($request);

        $this->assertFalse($action->authorizedToSee($request));
        $this->assertFalse($action->authorizedToRun($request, $this->track()));
    }
}
