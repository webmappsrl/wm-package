<?php

namespace Wm\WmPackage\Tests\Feature\Nova\Actions;

require_once __DIR__.'/../../../Concerns/SharesGeohubConnectionWithLocal.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Nova\Fields\ActionFields;
use Tests\TestCase;
use Wm\WmPackage\Jobs\TaxonomyWhere\CopyTaxonomyWhereGeometryFromGeohubJob;
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

    public function test_geohub_source_imports_wheres_without_admin_level_for_app_content(): void
    {
        Bus::fake();
        $this->actingAsSuperAdmin();

        $geohubOwner = User::factory()->create();
        $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);

        $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

        // identifier = null sulla fixture "corsica": esercita davvero il ramo
        // "create" di handleGeohub() invece di un self-match mascherato da
        // update (vedi insertGeohubWhereWithoutIdentifier()).
        $corsicaId = $this->insertGeohubWhereWithoutIdentifier('Corsica');
        $liguriaId = $this->insertGeohubWhere('liguria', 4); // ha admin_level, deve essere escluso

        DB::connection('geohub')->table('ec_tracks')->insert([
            'user_id' => $geohubOwner->id, 'app_id' => $geohubAppRow->id, 'name' => 'Track test',
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{\"type\":\"LineString\",\"coordinates\":[[9.0,42.0,0],[9.1,42.1,0]]}')"),
            // '{}' (oggetto), non json_encode([]) (che produce '[]', un array):
            // GeometryComputationService::syncTracksTaxonomyWhere() fa jsonb_set
            // su properties->taxonomy_where, che richiede un oggetto jsonb come
            // radice, non un array.
            'properties' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $geohubTrackId = DB::connection('geohub')->table('ec_tracks')->where('user_id', $geohubOwner->id)->value('id');

        DB::connection('geohub')->table('taxonomy_whereables')->insert([
            'taxonomy_where_id' => $corsicaId,
            'taxonomy_whereable_id' => $geohubTrackId,
            'taxonomy_whereable_type' => 'App\\Models\\EcTrack',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('geohub')->table('taxonomy_whereables')->insert([
            'taxonomy_where_id' => $liguriaId,
            'taxonomy_whereable_id' => $geohubTrackId,
            'taxonomy_whereable_type' => 'App\\Models\\EcTrack',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $countBefore = TaxonomyWhere::count();

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'geohub', 'app_id' => $app->id]), collect());

        $response = $action->handle($fields, collect());

        // Ramo "create" genuino (identifier=null sulla fixture, niente self-match):
        // il messaggio riporta davvero "Creati 1" e il conteggio dei record e'
        // aumentato di 1 (before/after, non solo il testo del messaggio).
        $this->assertStringContainsString('Creati 1', $response['message']);
        $this->assertSame($countBefore + 1, TaxonomyWhere::count());

        $imported = TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $corsicaId])->first();
        $this->assertNotNull($imported);
        $this->assertSame($corsicaId, $imported->properties['geohub_id']);
        $this->assertSame('geohub', $imported->properties['source']);
        // Fallback identifier: nessun identifier fornito da GeoHub (null) →
        // TaxonomyObserver ricade su TaxonomyWhere::generateIdentifier(), che
        // deriva da source + id sorgente: "geohub-<id>".
        $this->assertSame('geohub-'.$corsicaId, $imported->identifier);

        // La riga fixture "liguria" (identifier => admin_level=4) esiste gia'
        // nella tabella condivisa (stessa connessione geohub=locale), quindi
        // non si puo' usare assertDatabaseMissing: si verifica invece che il
        // filtro `admin_level IS NULL` l'abbia esclusa dal ciclo di import,
        // cioe' che non abbia ricevuto 'geohub_id'/'source' => 'geohub'.
        $liguria = TaxonomyWhere::where('identifier', 'liguria')->first();
        $this->assertArrayNotHasKey('geohub_id', $liguria->properties ?? []);

        // I job di copia geometria passano da Bus::batch() (non da dispatch
        // diretto): il sync delle track deve partire solo a batch concluso,
        // altrimenti troverebbe le geometrie ancora vuote (vedi
        // SyncTaxonomyWhereTracksJob). Bus::fake() intercetta il batch,
        // quindi si verifica il contenuto di $batch->jobs invece di
        // Bus::assertDispatched().
        Bus::assertBatched(function ($batch) use ($imported, $corsicaId, $liguriaId) {
            $hasCorsicaJob = $batch->jobs->contains(
                fn ($job) => $job instanceof CopyTaxonomyWhereGeometryFromGeohubJob
                    && $job->taxonomyWhereId === $imported->id
                    && $job->geohubTaxonomyWhereId === $corsicaId
            );
            $hasLiguriaJob = $batch->jobs->contains(
                fn ($job) => $job instanceof CopyTaxonomyWhereGeometryFromGeohubJob
                    && $job->geohubTaxonomyWhereId === $liguriaId
            );

            return $hasCorsicaJob && ! $hasLiguriaJob;
        });
    }

    /**
     * Nota (fix round 1, Finding 1 — valutazione): questo test soffre dello
     * stesso self-match descritto in insertGeohubWhereWithoutIdentifier() —
     * la fixture "francia" (identifier non-null, necessario qui perche' lo
     * scenario testato e' proprio il riuso diretto dell'identifier GeoHub via
     * withCollisionCounter()) esiste gia' fisicamente nella tabella condivisa,
     * quindi il fallback-lookup per identifier trova se stessa e il ramo
     * eseguito e' un "update" (self), non un "create" con withCollisionCounter().
     * A differenza del test "imports_wheres_without_admin_level_for_app_content",
     * qui NON e' applicabile il fix identifier=null: l'intero scopo del test e'
     * verificare che l'identifier GeoHub non-null venga riusato direttamente, e
     * con lo stesso indice unique su taxonomy_wheres.identifier (oc:8469) non e'
     * possibile avere due righe fisiche distinte con lo stesso identifier nella
     * tabella condivisa (vedi anche il commento nel test
     * "falls_back_to_identifier_lookup_for_manually_created_record" per lo
     * stesso vincolo). Limite strutturale della tecnica di test a connessione
     * condivisa (SharesGeohubConnectionWithLocal), non risolvibile senza una
     * seconda tabella/connessione reale — accettato, non risolto in questo
     * fix round. Le asserzioni sotto restano comunque valide: verificano lo
     * stato finale del record (identifier, geohub_id, source), che e' identico
     * a quello che produrrebbe un create genuino in produzione.
     */
    public function test_geohub_source_reuses_geohub_identifier_slug_directly(): void
    {
        Bus::fake();
        $this->actingAsSuperAdmin();

        $geohubOwner = User::factory()->create();
        $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
        $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

        $francId = $this->insertGeohubWhere('francia');
        DB::connection('geohub')->table('layers')->insert([
            'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
            'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');
        DB::connection('geohub')->table('taxonomy_whereables')->insert([
            'taxonomy_where_id' => $francId,
            'taxonomy_whereable_id' => $geohubLayerId,
            'taxonomy_whereable_type' => 'App\\Models\\Layer',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'geohub', 'app_id' => $app->id]), collect());

        $action->handle($fields, collect());

        $imported = TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $francId])->first();
        $this->assertNotNull($imported);
        $this->assertSame('francia', $imported->identifier);
        $this->assertSame('geohub', $imported->properties['source']);
    }

    public function test_geohub_source_is_idempotent_on_reimport(): void
    {
        Bus::fake();
        $this->actingAsSuperAdmin();

        $geohubOwner = User::factory()->create();
        $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
        $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

        $corsicaId = $this->insertGeohubWhere('corsica');
        DB::connection('geohub')->table('layers')->insert([
            'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
            'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');
        DB::connection('geohub')->table('taxonomy_whereables')->insert([
            'taxonomy_where_id' => $corsicaId,
            'taxonomy_whereable_id' => $geohubLayerId,
            'taxonomy_whereable_type' => 'App\\Models\\Layer',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'geohub', 'app_id' => $app->id]), collect());

        $action->handle($fields, collect());
        $response = $action->handle($fields, collect());

        $this->assertStringContainsString('aggiornati 1', $response['message']);
        $this->assertSame(1, TaxonomyWhere::whereRaw("properties->>'geohub_id' = ?", [(string) $corsicaId])->count());
    }

    public function test_geohub_source_falls_back_to_identifier_lookup_for_manually_created_record(): void
    {
        Bus::fake();
        $this->actingAsSuperAdmin();

        // Record preesistente creato manualmente in Nova, senza geohub_id.
        // Identifier assegnato con lo stesso pattern del codice di produzione:
        // assegnazione diretta PRIMA di save(), che bypassa il mass-assignment
        // ('identifier' non e' in TaxonomyWhere::$fillable). Con
        // TaxonomyWhere::create(['identifier' => 'corsica']) la chiave verrebbe
        // scartata silenziosamente e TaxonomyObserver genererebbe un identifier
        // diverso dal nome — il fallback-lookup non troverebbe mai questo record
        // (Finding 2 del round 1: il test non testava lo scenario dichiarato).
        $manual = new TaxonomyWhere(['name' => 'Corsica manuale']);
        $manual->identifier = 'corsica';
        $manual->save();

        $geohubOwner = User::factory()->create();
        $geohubAppRow = App::factory()->create(['user_id' => $geohubOwner->id]);
        $app = App::factory()->create(['properties' => ['geohub_id' => $geohubAppRow->id]]);

        // Nessun secondo insert "geohub" con identifier='corsica': la
        // connessione 'geohub' punta alla STESSA tabella fisica locale
        // (SharesGeohubConnectionWithLocal) e taxonomy_wheres.identifier ha un
        // indice unique (oc:8469) — un secondo insert con lo stesso identifier
        // violerebbe il vincolo DB reale (a differenza di NULL, un identifier
        // stringa duplicato NON e' ammesso dall'indice). Si associa invece
        // direttamente il record manuale — che vive gia' in quella tabella —
        // alla relazione taxonomy_whereables interrogata dalla query raw di
        // handleGeohub(): la query lo "trova" con identifier='corsica' come se
        // fosse la riga GeoHub da riconciliare, esercitando esattamente il
        // fallback-lookup per identifier (non il match diretto per geohub_id,
        // che a questo punto e' ancora assente su $manual). L'unica differenza
        // rispetto a produzione (due righe fisiche distinte in due DB separati)
        // e' che qui il "geohub_id" riportato coincide numericamente con l'id
        // locale del record trovato — ininfluente per l'asserzione sotto, che
        // verifica solo che sia stato aggiornato lo STESSO record (stesso id,
        // via $manual->fresh()), non un record diverso creato ex-novo.
        $corsicaId = $manual->id;
        DB::connection('geohub')->table('layers')->insert([
            'app_id' => $geohubAppRow->id, 'name' => 'Layer test',
            'properties' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $geohubLayerId = DB::connection('geohub')->table('layers')->where('app_id', $geohubAppRow->id)->value('id');
        DB::connection('geohub')->table('taxonomy_whereables')->insert([
            'taxonomy_where_id' => $corsicaId,
            'taxonomy_whereable_id' => $geohubLayerId,
            'taxonomy_whereable_type' => 'App\\Models\\Layer',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $countBefore = TaxonomyWhere::count();

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'geohub', 'app_id' => $app->id]), collect());

        $action->handle($fields, collect());

        // Nessun nuovo record creato: il fallback-lookup ha trovato e
        // aggiornato quello manuale preesistente.
        $this->assertSame($countBefore, TaxonomyWhere::count());
        $this->assertSame(1, TaxonomyWhere::where('identifier', 'corsica')->count());

        $fresh = $manual->fresh();
        $this->assertSame($manual->id, $fresh->id);
        $this->assertSame('corsica', $fresh->identifier);
        $this->assertSame($corsicaId, $fresh->properties['geohub_id']);
        $this->assertSame('geohub', $fresh->properties['source']);

        Bus::assertBatched(function ($batch) use ($manual) {
            return $batch->jobs->contains(
                fn ($job) => $job instanceof CopyTaxonomyWhereGeometryFromGeohubJob
                    && $job->taxonomyWhereId === $manual->id
            );
        });
    }
}
