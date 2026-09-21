<?php

namespace Wm\WmPackage\Tests\Feature\Nova\Actions;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\Actions\ReverseEcTrackGeometryAction;
use Wm\WmPackage\Tests\TestCase;

class ReverseEcTrackGeometryActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    protected function createTrackWithGeometry(array $properties = []): EcTrack
    {
        return EcTrack::factory()->createQuietly([
            'geometry' => DB::raw("ST_GeomFromText('MULTILINESTRING Z((0 0 0, 1 1 100, 2 2 200))', 4326)"),
            'properties' => $properties,
        ]);
    }

    public function test_it_reverses_the_geometry_in_the_database()
    {
        $track = $this->createTrackWithGeometry();

        $originalWkt = DB::selectOne(
            'SELECT ST_AsText(geometry::geometry) as wkt FROM ec_tracks WHERE id = ?',
            [$track->id]
        )->wkt;

        $expectedWkt = DB::selectOne(
            'SELECT ST_AsText(ST_Reverse(ST_GeomFromText(?))) as wkt',
            [$originalWkt]
        )->wkt;

        (new ReverseEcTrackGeometryAction)->handle(
            new ActionFields(collect(), collect()),
            collect([$track])
        );

        $actualWkt = DB::selectOne(
            'SELECT ST_AsText(geometry::geometry) as wkt FROM ec_tracks WHERE id = ?',
            [$track->id]
        )->wkt;

        $this->assertNotEquals($originalWkt, $actualWkt);
        $this->assertEquals($expectedWkt, $actualWkt);
    }

    public function test_it_dispatches_the_dem_recalculation_job()
    {
        $track = $this->createTrackWithGeometry();

        (new ReverseEcTrackGeometryAction)->handle(
            new ActionFields(collect(), collect()),
            collect([$track])
        );

        Bus::assertDispatched(UpdateEcTrackDemJob::class);
    }

    public function test_it_dispatches_the_recalculation_even_though_eloquent_never_detects_the_write()
    {
        // La scrittura della geometria avviene via SQL puro (DB::statement), non tramite
        // save() Eloquent: l'evento "updated" di Eloquent (e quindi EcTrackObserver::updated(),
        // che dispatcha EcTrackService::updateDataChain() solo se wasChanged('geometry')) non
        // si attiva mai. Questo test fallisce se in futuro l'azione venisse riscritta assumendo
        // che basti il salvataggio Eloquent per far scattare il ricalcolo.
        Event::fake(['eloquent.updated: '.EcTrack::class]);

        $track = $this->createTrackWithGeometry();

        (new ReverseEcTrackGeometryAction)->handle(
            new ActionFields(collect(), collect()),
            collect([$track])
        );

        Event::assertNotDispatched('eloquent.updated: '.EcTrack::class);
        Bus::assertDispatched(UpdateEcTrackDemJob::class);
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

        $this->assertStringNotContainsString('Warning', json_encode($result));
    }
}
