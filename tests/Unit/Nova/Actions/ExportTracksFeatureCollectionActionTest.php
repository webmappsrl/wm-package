<?php

namespace Wm\WmPackage\Tests\Unit\Nova\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\Actions\ExportTracksFeatureCollectionAction;
use Wm\WmPackage\Tests\TestCase;

class ExportTracksFeatureCollectionActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Carbon::setTestNow(Carbon::parse('2026-04-16 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_exports_selected_tracks_to_geojson_and_returns_signed_redirect_url()
    {
        $app = App::factory()->createQuietly();

        $track1 = EcTrack::factory()->createQuietly(['app_id' => $app->id]);
        $track2 = EcTrack::factory()->createQuietly(['app_id' => $app->id]);

        $action = new ExportTracksFeatureCollectionAction();
        $result = $action->handle(new ActionFields(collect(), collect()), collect([$track1, $track2]));

        $this->assertInstanceOf(ActionResponse::class, $result);

        $fileName = 'tracks_export_2026-04-16.geojson';
        $this->assertTrue(Storage::disk('public')->exists($fileName));

        $payload = json_decode(Storage::disk('public')->get($fileName), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('FeatureCollection', $payload['type'] ?? null);
        $this->assertCount(2, $payload['features'] ?? []);

        foreach ($payload['features'] as $feature) {
            $this->assertSame('Feature', $feature['type'] ?? null);
        }

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('redirect', $encoded);
        $this->assertStringContainsString($fileName, $encoded);
        $this->assertStringContainsString('signature=', $encoded);
    }

    public function test_it_rejects_selection_with_mixed_app_ids()
    {
        $app1 = App::factory()->createQuietly();
        $app2 = App::factory()->createQuietly();

        $track1 = EcTrack::factory()->createQuietly(['app_id' => $app1->id]);
        $track2 = EcTrack::factory()->createQuietly(['app_id' => $app2->id]);

        $action = new ExportTracksFeatureCollectionAction();
        $result = $action->handle(new ActionFields(collect(), collect()), collect([$track1, $track2]));

        $this->assertInstanceOf(ActionResponse::class, $result);
        $this->assertStringContainsString(
            'All selected tracks must belong to the same app.',
            json_encode($result, JSON_THROW_ON_ERROR)
        );
    }

    public function test_it_rejects_selection_without_track_models()
    {
        $app = App::factory()->createQuietly();

        $action = new ExportTracksFeatureCollectionAction();
        $result = $action->handle(new ActionFields(collect(), collect()), collect([$app]));

        $this->assertInstanceOf(ActionResponse::class, $result);
        $this->assertStringContainsString(
            'No track models in the selection.',
            json_encode($result, JSON_THROW_ON_ERROR)
        );
    }
}

