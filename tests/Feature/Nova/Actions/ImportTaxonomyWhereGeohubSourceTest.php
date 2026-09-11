<?php

namespace Wm\WmPackage\Tests\Feature\Nova\Actions;

require_once __DIR__.'/../../../Concerns/SharesGeohubConnectionWithLocal.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Nova\Actions\Responses\Modal;
use Laravel\Nova\Fields\ActionFields;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Actions\ImportTaxonomyWhere;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class ImportTaxonomyWhereGeohubSourceTest extends TestCase
{
    use DatabaseTransactions, SharesGeohubConnectionWithLocal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();

        // La tabella locale `taxonomy_wheres` non ha una colonna fisica
        // `admin_level` (qui vive in `properties`, scelta di oc:8469) mentre lo
        // schema reale di GeoHub si' (query raw in handleGeohub()). Il trait
        // SharesGeohubConnectionWithLocal punta la connessione "geohub" sulla
        // STESSA tabella locale per evitare un secondo DB nei test: la colonna
        // va quindi aggiunta qui, solo per la durata della transazione di
        // test (DatabaseTransactions la fa rollback a fine test, nessun
        // impatto sullo schema reale/condiviso).
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

    public function test_geohub_source_returns_danger_for_non_super_admin(): void
    {
        $user = User::factory()->create(['email' => 'nobody@example.com']);
        $this->actingAs($user);

        $app = App::factory()->create(['properties' => ['geohub_id' => 999]]);

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'geohub', 'app_id' => $app->id]), collect());

        $response = $action->handle($fields, collect());

        $this->assertStringContainsString('super-admin', $response['danger']);
    }

    public function test_geohub_source_returns_danger_when_app_has_no_geohub_id(): void
    {
        $this->actingAsSuperAdmin();

        $app = App::factory()->create(['properties' => []]);

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'geohub', 'app_id' => $app->id]), collect());

        $response = $action->handle($fields, collect());

        $this->assertStringContainsString('geohub_id assente', $response['danger']);
    }

    public function test_geohub_source_returns_danger_when_geohub_app_row_is_missing(): void
    {
        $this->actingAsSuperAdmin();

        // 999999 non corrisponde a nessuna riga "apps" esistente (stessa tabella, connessione condivisa nei test)
        $app = App::factory()->create(['properties' => ['geohub_id' => 999999]]);

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'geohub', 'app_id' => $app->id]), collect());

        $response = $action->handle($fields, collect());

        $this->assertStringContainsString('user GeoHub', $response['danger']);
    }

    private function insertGeohubWhere(string $identifier, ?int $adminLevel = null): int
    {
        return DB::connection('geohub')->table('taxonomy_wheres')->insertGetId([
            'name' => json_encode(['it' => ucfirst($identifier), 'en' => ucfirst($identifier)]),
            'identifier' => $identifier,
            'admin_level' => $adminLevel,
            'source' => 'osm',
            'properties' => json_encode([]),
            'geometry' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Variante senza identifier: SharesGeohubConnectionWithLocal fa puntare la
     * connessione "geohub" sulla STESSA tabella fisica locale taxonomy_wheres,
     * quindi una fixture con identifier non-null e' immediatamente visibile
     * anche al fallback-lookup locale per identifier di handleGeohub() (si
     * troverebbe da sola, mascherando un "create" da un "update"). Con
     * identifier = null il guard `if (! $existing && $row->identifier)` non
     * scatta mai (Postgres inoltre non considera NULL in collisione con altri
     * NULL sull'indice unique), quindi il codice arriva davvero al ramo
     * "crea nuovo record" — replica lo scenario reale (connessione geohub
     * separata) dove il self-match non puo' verificarsi.
     */
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

    public function test_geohub_source_returns_action_modal_with_candidate_rows(): void
    {
        $this->actingAsSuperAdmin();

        $geohubOwner = User::factory()->create();
        $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
        $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

        $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
        DB::connection('geohub')->table('layers')->insert([
            'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
            'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');
        DB::connection('geohub')->table('taxonomy_whereables')->insert([
            'taxonomy_where_id' => $corsicaId, 'taxonomy_whereable_id' => $geohubLayerId,
            'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'geohub', 'app_id' => $app->id]), collect());

        $response = $action->handle($fields, collect());

        $modal = $response['modal'];
        $this->assertInstanceOf(Modal::class, $modal);
        $this->assertSame(ImportTaxonomyWhere::GEOHUB_WHERE_SELECTION_MODAL_COMPONENT, $modal->component);
        $this->assertSame($app->id, $modal->payload['app_id']);
        $this->assertCount(1, $modal->payload['rows']);
        $this->assertSame((string) $corsicaId, $modal->payload['rows'][0]['id']);
        $this->assertTrue($modal->payload['rows'][0]['checked']);
        $this->assertStringContainsString('Corsica', $modal->payload['rows'][0]['label']);
    }

    public function test_geohub_source_excludes_rows_with_admin_level_from_modal_payload(): void
    {
        $this->actingAsSuperAdmin();

        $geohubOwner = User::factory()->create();
        $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
        $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

        $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
        $liguriaId = $this->insertGeohubWhere('liguria-'.Str::lower(Str::random(8)), 4);

        DB::connection('geohub')->table('layers')->insert([
            'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
            'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');
        DB::connection('geohub')->table('taxonomy_whereables')->insert([
            ['taxonomy_where_id' => $corsicaId, 'taxonomy_whereable_id' => $geohubLayerId, 'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now()],
            ['taxonomy_where_id' => $liguriaId, 'taxonomy_whereable_id' => $geohubLayerId, 'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'geohub', 'app_id' => $app->id]), collect());

        $response = $action->handle($fields, collect());

        $ids = array_column($response['modal']->payload['rows'], 'id');
        $this->assertContains((string) $corsicaId, $ids);
        $this->assertNotContains((string) $liguriaId, $ids, 'liguria ha admin_level, non deve comparire nel payload');
    }

    public function test_geohub_source_labels_already_imported_rows_in_modal_payload(): void
    {
        $this->actingAsSuperAdmin();

        $geohubOwner = User::factory()->create();
        $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
        $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

        $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
        DB::connection('geohub')->table('layers')->insert([
            'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
            'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');
        DB::connection('geohub')->table('taxonomy_whereables')->insert([
            'taxonomy_where_id' => $corsicaId, 'taxonomy_whereable_id' => $geohubLayerId,
            'taxonomy_whereable_type' => 'App\\Models\\Layer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Simula una where già importata con lo stesso geohub_id.
        $imported = new TaxonomyWhere(['name' => 'Corsica']);
        $imported->identifier = 'imported-'.Str::lower(Str::random(8));
        $imported->properties = ['geohub_id' => $corsicaId, 'source' => 'geohub'];
        $imported->save();

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'geohub', 'app_id' => $app->id]), collect());

        $response = $action->handle($fields, collect());

        $row = collect($response['modal']->payload['rows'])->firstWhere('id', (string) $corsicaId);
        $this->assertNotNull($row);
        $this->assertStringContainsString('già importata', $row['label']);
    }
}
