<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;
use Wm\WmPackage\Tests\TestCase;

class LayerServiceAssignTracksByTaxonomyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_assign_tracks_by_taxonomy_accepts_app_model_morph_type_for_tracks(): void
    {
        $app = App::factory()->createQuietly();
        $track = EcTrack::factory()->createQuietly(['app_id' => $app->id]);

        $layer = Layer::factory()->createQuietly([
            'app_id' => $app->id,
            'configuration' => ['track_mode' => 'auto'],
        ]);

        $taxonomyId = DB::table('taxonomy_activities')->insertGetId([
            'name' => json_encode(['it' => 'horse']),
            'description' => null,
            'excerpt' => null,
            'identifier' => 'horse-'.uniqid(),
            'properties' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tassonomia sul layer.
        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => $taxonomyId,
            'taxonomy_activityable_type' => Layer::class,
            'taxonomy_activityable_id' => $layer->id,
            'duration_forward' => 0,
            'duration_backward' => 0,
        ]);

        // Caso reale visto in produzione: pivot scritto con App\Models\EcTrack.
        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => $taxonomyId,
            'taxonomy_activityable_type' => 'App\\Models\\EcTrack',
            'taxonomy_activityable_id' => $track->id,
            'duration_forward' => 0,
            'duration_backward' => 0,
        ]);

        /** @var LayerService $layerService */
        $layerService = app(LayerService::class);
        $layerService->assignTracksByTaxonomy($layer->fresh());

        Assert::assertTrue(
            $layer->fresh()->ecTracks()->where('ec_tracks.id', $track->id)->exists(),
            'La track deve essere sincronizzata anche con morph type App\\Models\\EcTrack'
        );
    }

    public function test_assign_tracks_by_taxonomy_accepts_package_model_morph_type_for_tracks(): void
    {
        $app = App::factory()->createQuietly();
        $track = EcTrack::factory()->createQuietly(['app_id' => $app->id]);

        $layer = Layer::factory()->createQuietly([
            'app_id' => $app->id,
            'configuration' => ['track_mode' => 'auto'],
        ]);

        $taxonomyId = DB::table('taxonomy_activities')->insertGetId([
            'name' => json_encode(['it' => 'trekking']),
            'description' => null,
            'excerpt' => null,
            'identifier' => 'trekking-'.uniqid(),
            'properties' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => $taxonomyId,
            'taxonomy_activityable_type' => Layer::class,
            'taxonomy_activityable_id' => $layer->id,
            'duration_forward' => 0,
            'duration_backward' => 0,
        ]);

        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => $taxonomyId,
            'taxonomy_activityable_type' => EcTrack::class,
            'taxonomy_activityable_id' => $track->id,
            'duration_forward' => 0,
            'duration_backward' => 0,
        ]);

        /** @var LayerService $layerService */
        $layerService = app(LayerService::class);
        $layerService->assignTracksByTaxonomy($layer->fresh());

        Assert::assertTrue(
            $layer->fresh()->ecTracks()->where('ec_tracks.id', $track->id)->exists(),
            'La track deve essere sincronizzata anche con morph type Wm\\WmPackage\\Models\\EcTrack'
        );
    }
}
