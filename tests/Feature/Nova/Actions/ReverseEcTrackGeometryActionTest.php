<?php

namespace Wm\WmPackage\Tests\Feature\Nova\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Jobs\Pbf\GenerateEcTrackPBFBatch;
use Wm\WmPackage\Jobs\Track\UpdateEcTrack3DDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAppRelationsInfoJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackCurrentDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackGenerateElevationChartImage;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackManualDataJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackOrderRelatedPoi;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackSlopeValues;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Actions\ReverseEcTrackGeometryAction;
use Wm\WmPackage\Nova\EcTrack as EcTrackResource;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Tests\TestCase;

class ReverseEcTrackGeometryActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    protected function createTrackWithGeometry(
        array $properties = [],
        string $wkt = 'MULTILINESTRING Z((0 0 0, 1 1 100, 2 2 200))'
    ): EcTrack {
        return EcTrack::factory()->createQuietly([
            'geometry' => DB::raw("ST_GeomFromText('{$wkt}', 4326)"),
            'properties' => $properties,
        ]);
    }

    protected function wktOf(EcTrack $track): string
    {
        return DB::selectOne(
            'SELECT ST_AsText(geometry::geometry) as wkt FROM ec_tracks WHERE id = ?',
            [$track->id]
        )->wkt;
    }

    /**
     * canSee()/canRun() sono agganciati in EcTrack::actions(), non sulla classe Action: vanno
     * risolti tramite la resource Nova, non con `new ReverseEcTrackGeometryAction` (oc:8569).
     */
    protected function resolveReverseAction(NovaRequest $request): ReverseEcTrackGeometryAction
    {
        $actions = (new EcTrackResource(new EcTrack))->actions($request);

        return collect($actions)->first(fn ($a) => $a instanceof ReverseEcTrackGeometryAction);
    }

    public function test_it_reverses_the_geometry_in_the_database()
    {
        $track = $this->createTrackWithGeometry();

        $originalWkt = $this->wktOf($track);

        $expectedWkt = DB::selectOne(
            'SELECT ST_AsText(ST_Reverse(ST_GeomFromText(?))) as wkt',
            [$originalWkt]
        )->wkt;

        (new ReverseEcTrackGeometryAction)->handle(
            new ActionFields(collect(), collect()),
            collect([$track])
        );

        $actualWkt = $this->wktOf($track);

        $this->assertNotEquals($originalWkt, $actualWkt);
        $this->assertEquals($expectedWkt, $actualWkt);
    }

    public function test_it_reverses_a_multipart_geometry_including_the_order_of_the_parts()
    {
        // Due tratti distinti e non contigui: la traccia va da (0 0) a (11 11).
        // Dopo l'inversione deve andare da (11 11) a (0 0) — quindi non basta ribaltare i
        // vertici dentro ogni tratto, va ribaltato anche l'ordine dei tratti (oc:8543).
        $track = $this->createTrackWithGeometry(
            [],
            'MULTILINESTRING Z((0 0 0, 1 1 100),(10 10 200, 11 11 300))'
        );

        (new ReverseEcTrackGeometryAction)->handle(
            new ActionFields(collect(), collect()),
            collect([$track])
        );

        $wkt = $this->wktOf($track);

        $this->assertEquals(
            'MULTILINESTRING Z ((11 11 300,10 10 200),(1 1 100,0 0 0))',
            $wkt
        );
    }

    public function test_it_dispatches_the_full_recalculation_chain_even_though_eloquent_never_detects_the_write()
    {
        // La scrittura della geometria avviene via SQL puro (DB::statement), non tramite
        // save() Eloquent: l'evento "updated" di Eloquent (e quindi EcTrackObserver::updated())
        // non si attiva mai. L'azione forza esplicitamente l'intera data chain con
        // updateDataChain(forceGeometryChain: true) — non solo il job DEM: questo test fallisce
        // se in futuro l'azione venisse riscritta assumendo che basti il salvataggio Eloquent,
        // o se qualcuno tornasse a dispatchare solo un sottoinsieme della catena (oc:8543).
        Event::fake(['eloquent.updated: '.EcTrack::class]);

        $track = $this->createTrackWithGeometry();

        (new ReverseEcTrackGeometryAction)->handle(
            new ActionFields(collect(), collect()),
            collect([$track])
        );

        Event::assertNotDispatched('eloquent.updated: '.EcTrack::class);
        Bus::assertChained([
            UpdateEcTrackDemJob::class,
            UpdateEcTrackManualDataJob::class,
            UpdateEcTrackCurrentDataJob::class,
            UpdateEcTrack3DDemJob::class,
            UpdateEcTrackSlopeValues::class,
            UpdateModelWithGeometryTaxonomyWhere::class,
            UpdateEcTrackGenerateElevationChartImage::class,
            GenerateEcTrackPBFBatch::class,
            UpdateEcTrackAwsJob::class,
            UpdateEcTrackAppRelationsInfoJob::class,
            UpdateEcTrackOrderRelatedPoi::class,
        ]);
    }

    public function test_it_warns_about_manual_overrides_on_direction_dependent_fields()
    {
        $track = $this->createTrackWithGeometry([
            'manual_data' => ['ascent' => 500],
        ]);

        $result = (new ReverseEcTrackGeometryAction)->handle(
            new ActionFields(collect(), collect()),
            collect([$track])
        );

        $this->assertStringContainsString('ascent', json_encode($result));
    }

    public function test_it_does_not_warn_without_manual_overrides()
    {
        $track = $this->createTrackWithGeometry();

        $result = (new ReverseEcTrackGeometryAction)->handle(
            new ActionFields(collect(), collect()),
            collect([$track])
        );

        // Non si asserisce sulla parola inglese 'Warning': con locale non-inglese il messaggio
        // sarebbe tradotto e l'assert passerebbe sempre, indipendentemente dal codice (oc:8543).
        // Il nome del campo comparirebbe in :fields solo se ci fosse un warning da mostrare.
        $this->assertStringNotContainsString('ascent', json_encode($result));
    }

    public function test_administrator_can_see_and_run_the_action()
    {
        RolesAndPermissionsService::seedDatabase();
        $administrator = User::factory()->create();
        $administrator->assignRole('Administrator');

        Auth::login($administrator);
        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $administrator);

        $action = $this->resolveReverseAction($request);

        $this->assertTrue($action->authorizedToSee($request));
        $this->assertTrue($action->authorizedToRun($request, $this->createTrackWithGeometry()));
    }

    public function test_editor_cannot_see_or_run_the_action()
    {
        // L'azione inverte una geometria pubblicata senza conferma né annullamento: ristretta
        // ad Administrator (oc:8543). Editor e Contributor non devono poterla eseguire.
        RolesAndPermissionsService::seedDatabase();
        $editor = User::factory()->create();
        $editor->assignRole('Editor');

        Auth::login($editor);
        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $editor);

        $action = $this->resolveReverseAction($request);

        $this->assertFalse($action->authorizedToSee($request));
        $this->assertFalse($action->authorizedToRun($request, $this->createTrackWithGeometry()));
    }
}
