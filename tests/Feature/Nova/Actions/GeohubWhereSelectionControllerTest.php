<?php

namespace Wm\WmPackage\Tests\Feature\Nova\Actions;

require_once __DIR__.'/../../../Concerns/SharesGeohubConnectionWithLocal.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Wm\WmPackage\Jobs\TaxonomyWhere\CopyTaxonomyWhereGeometryFromGeohubJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class GeohubWhereSelectionControllerTest extends TestCase
{
    use DatabaseTransactions, SharesGeohubConnectionWithLocal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();

        if (! Schema::hasColumn('taxonomy_wheres', 'admin_level')) {
            Schema::table('taxonomy_wheres', function (Blueprint $table) {
                $table->integer('admin_level')->nullable();
            });
        }
        if (! Schema::hasColumn('taxonomy_wheres', 'source')) {
            Schema::table('taxonomy_wheres', function (Blueprint $table) {
                $table->text('source')->nullable();
            });
        }

        config(['wm-package.super_admin_emails' => ['super@webmapp.it']]);
    }

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['email' => 'super@webmapp.it']);
        $this->actingAs($user);

        return $user;
    }

    private function insertGeohubWhereWithoutIdentifier(string $name, ?int $adminLevel = null): int
    {
        return DB::connection('geohub')->table('taxonomy_wheres')->insertGetId([
            'name' => json_encode(['it' => $name, 'en' => $name]),
            'identifier' => null,
            'admin_level' => $adminLevel,
            'source' => 'osm',
            'properties' => json_encode([]),
            'geometry' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeAppWithGeohubLayer(): array
    {
        $geohubOwner = User::factory()->create();
        $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
        $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

        DB::connection('geohub')->table('layers')->insert([
            'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
            'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');

        return [$app, $geohubLayerId];
    }

    private function linkWhereToLayer(int $whereId, int $layerId): void
    {
        DB::connection('geohub')->table('taxonomy_whereables')->insert([
            'taxonomy_where_id' => $whereId, 'taxonomy_whereable_id' => $layerId,
            'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_import_returns_403_for_non_super_admin(): void
    {
        $user = User::factory()->create(['email' => 'nobody@example.com']);
        $this->actingAs($user);

        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', [
            'app_id' => 999, 'selected_ids' => ['1'],
        ]);

        $response->assertStatus(403);
    }

    public function test_import_returns_422_when_app_not_found(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', [
            'app_id' => 999999, 'selected_ids' => ['1'],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('App non trovata', $response->json('message'));
    }

    /**
     * Regressione: App::find('not-a-number') lanciava un QueryException non
     * gestito su Postgres ("invalid input syntax for type bigint"), producendo
     * un 500 invece di un 422 pulito — trovato in review, fix con is_numeric().
     */
    public function test_import_returns_422_when_app_id_is_not_numeric(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', [
            'app_id' => 'not-a-number', 'selected_ids' => ['1'],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('App non trovata', $response->json('message'));
    }

    public function test_import_returns_403_for_anonymous_request(): void
    {
        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', [
            'app_id' => 999, 'selected_ids' => ['1'],
        ]);

        $response->assertStatus(403);
    }

    public function test_import_returns_422_when_app_not_linked_to_geohub(): void
    {
        $this->actingAsSuperAdmin();
        $app = App::factory()->create(['properties' => []]);

        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', [
            'app_id' => $app->id, 'selected_ids' => ['1'],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('geohub_id assente', $response->json('message'));
    }

    public function test_import_returns_422_when_selection_is_empty(): void
    {
        $this->actingAsSuperAdmin();
        [$app, $geohubLayerId] = $this->makeAppWithGeohubLayer();
        $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
        $this->linkWhereToLayer($corsicaId, $geohubLayerId);

        $countBefore = TaxonomyWhere::count();

        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', [
            'app_id' => $app->id, 'selected_ids' => [],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Nessuna where selezionata', $response->json('message'));
        $this->assertSame($countBefore, TaxonomyWhere::count());
    }

    public function test_import_ignores_ids_outside_candidate_set(): void
    {
        Bus::fake();
        $this->actingAsSuperAdmin();
        [$app, $geohubLayerId] = $this->makeAppWithGeohubLayer();
        $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
        $this->linkWhereToLayer($corsicaId, $geohubLayerId);

        // 999999999 non è nel set candidato per questa App (nessun link a
        // taxonomy_whereables) — un payload alterato/non aggiornato non deve
        // produrre un import fuori scope, deve semplicemente essere ignorato.
        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', [
            'app_id' => $app->id, 'selected_ids' => [(string) $corsicaId, '999999999'],
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('created'));
        $imported = TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", ['999999999'])->first();
        $this->assertNull($imported);
    }

    public function test_import_creates_and_updates_selected_wheres_only(): void
    {
        Bus::fake();
        $this->actingAsSuperAdmin();
        [$app, $geohubLayerId] = $this->makeAppWithGeohubLayer();
        $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
        $franciaId = $this->insertGeohubWhereWithoutIdentifier('Francia');
        $this->linkWhereToLayer($corsicaId, $geohubLayerId);
        $this->linkWhereToLayer($franciaId, $geohubLayerId);

        // Solo corsica selezionata: francia è candidata ma non selezionata,
        // non deve essere importata.
        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', [
            'app_id' => $app->id, 'selected_ids' => [(string) $corsicaId],
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('created'));
        $this->assertSame(0, $response->json('updated'));

        $imported = TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $corsicaId])->first();
        $this->assertNotNull($imported);
        $this->assertSame('Corsica', $imported->getTranslation('name', 'it'));
        $notImported = TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $franciaId])->first();
        $this->assertNull($notImported);

        Bus::assertBatched(function ($batch) use ($imported) {
            return $batch->jobs->contains(
                fn ($job) => $job instanceof CopyTaxonomyWhereGeometryFromGeohubJob
                    && $job->taxonomyWhereId === $imported->id
            );
        });
    }

    public function test_import_is_idempotent_on_reimport(): void
    {
        Bus::fake();
        $this->actingAsSuperAdmin();
        [$app, $geohubLayerId] = $this->makeAppWithGeohubLayer();

        $identifier = 'test-'.Str::lower(Str::random(8));
        $corsicaId = DB::connection('geohub')->table('taxonomy_wheres')->insertGetId([
            'name' => json_encode(['it' => 'Corsica', 'en' => 'Corsica']),
            'identifier' => $identifier, 'admin_level' => null, 'source' => 'osm',
            'properties' => json_encode([]), 'geometry' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->linkWhereToLayer($corsicaId, $geohubLayerId);

        $payload = ['app_id' => $app->id, 'selected_ids' => [(string) $corsicaId]];

        $this->postJson('/nova-vendor/geohub-where-selection/import', $payload);
        $response = $this->postJson('/nova-vendor/geohub-where-selection/import', $payload);

        $response->assertOk();
        $this->assertSame(0, $response->json('created'));
        $this->assertSame(1, $response->json('updated'));
        $this->assertSame(1, TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $corsicaId])->count());
    }
}
