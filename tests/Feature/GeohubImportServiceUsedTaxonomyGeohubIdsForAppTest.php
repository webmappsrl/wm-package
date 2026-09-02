<?php

namespace Wm\WmPackage\Tests\Feature;

require_once __DIR__.'/../Concerns/SharesGeohubConnectionWithLocal.php';
require_once __DIR__.'/../Concerns/SimulatesGeohubMediaSchema.php';
require_once __DIR__.'/../Concerns/DisablesForeignKeyConstraints.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Tests\Concerns\DisablesForeignKeyConstraints;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;
use Wm\WmPackage\Tests\Concerns\SimulatesGeohubMediaSchema;

class GeohubImportServiceUsedTaxonomyGeohubIdsForAppTest extends TestCase
{
    use DatabaseTransactions, DisablesForeignKeyConstraints, SharesGeohubConnectionWithLocal, SimulatesGeohubMediaSchema;

    private GeohubImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();
        $this->disableForeignKeyConstraints();
        $this->simulateGeohubMediaSchema();

        $this->service = app(GeohubImportService::class);
    }

    protected function tearDown(): void
    {
        $this->restoreForeignKeyConstraints();

        parent::tearDown();
    }

    public function test_theme_used_by_an_ec_track_of_the_app_is_included(): void
    {
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        $track = EcTrack::factory()->createQuietly(['user_id' => $appUser->id]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 9101,
            'taxonomy_themeable_type' => 'App\\Models\\EcTrack',
            'taxonomy_themeable_id' => $track->id,
        ]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertEqualsCanonicalizing([9101], $result);
    }

    public function test_theme_used_by_an_ec_poi_of_the_app_is_included(): void
    {
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        $poi = EcPoi::factory()->createQuietly(['user_id' => $appUser->id]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 9102,
            'taxonomy_themeable_type' => 'App\\Models\\EcPoi',
            'taxonomy_themeable_id' => $poi->id,
        ]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertEqualsCanonicalizing([9102], $result);
    }

    public function test_theme_used_only_by_a_layer_of_the_app_is_included(): void
    {
        // Regression guard for the risk flagged in overview.md: Layer has no child-side sync
        // channel and depends solely on the dedicated taxonomy job — scoping must not starve it.
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        $layer = Layer::factory()->createQuietly(['app_id' => $app->id]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 9103,
            'taxonomy_themeable_type' => 'App\\Models\\Layer',
            'taxonomy_themeable_id' => $layer->id,
        ]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertEqualsCanonicalizing([9103], $result);
    }

    public function test_theme_used_only_by_the_app_itself_is_included(): void
    {
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 9104,
            'taxonomy_themeable_type' => 'App\\Models\\App',
            'taxonomy_themeable_id' => $app->id,
        ]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertEqualsCanonicalizing([9104], $result);
    }

    public function test_theme_used_by_an_ec_media_associated_via_track_feature_image_is_included(): void
    {
        // Regression guard for the risk flagged in overview.md: EcMedia has no child-side sync
        // channel either — exercises the getEcMediaIdsForApp() feature_image path it reuses.
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        $mediaId = DB::table('ec_media')->insertGetId([
            'name' => json_encode(['it' => 'Test Media']),
            'url' => 'https://example.test/media.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        EcTrack::factory()->createQuietly([
            'user_id' => $appUser->id,
            'feature_image' => $mediaId,
        ]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 9105,
            'taxonomy_themeable_type' => 'App\\Models\\EcMedia',
            'taxonomy_themeable_id' => $mediaId,
        ]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertEqualsCanonicalizing([9105], $result);
    }

    public function test_theme_not_used_by_anything_of_this_app_is_excluded(): void
    {
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        // Belongs to a different, unrelated user/app entirely.
        $otherUser = User::factory()->create();
        $otherTrack = EcTrack::factory()->createQuietly(['user_id' => $otherUser->id]);

        DB::table('taxonomy_themeables')->insert([
            'taxonomy_theme_id' => 9106,
            'taxonomy_themeable_type' => 'App\\Models\\EcTrack',
            'taxonomy_themeable_id' => $otherTrack->id,
        ]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertEqualsCanonicalizing([], $result);
    }

    public function test_result_has_no_duplicates_when_same_theme_used_by_multiple_sources(): void
    {
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        $track = EcTrack::factory()->createQuietly(['user_id' => $appUser->id]);
        $poi = EcPoi::factory()->createQuietly(['user_id' => $appUser->id]);

        DB::table('taxonomy_themeables')->insert([
            ['taxonomy_theme_id' => 9107, 'taxonomy_themeable_type' => 'App\\Models\\EcTrack', 'taxonomy_themeable_id' => $track->id],
            ['taxonomy_theme_id' => 9107, 'taxonomy_themeable_type' => 'App\\Models\\EcPoi', 'taxonomy_themeable_id' => $poi->id],
        ]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertEqualsCanonicalizing([9107], $result);
    }

    public function test_returns_empty_array_when_app_has_no_content_at_all(): void
    {
        $appUser = User::factory()->create();
        $app = App::factory()->createQuietly(['user_id' => $appUser->id]);

        $result = $this->service->getUsedTaxonomyGeohubIdsForApp('taxonomy_theme', $app->id, $appUser->id);

        $this->assertSame([], $result);
    }
}
