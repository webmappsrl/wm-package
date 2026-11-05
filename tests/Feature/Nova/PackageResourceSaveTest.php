<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\TabsGroup;
use Tests\TestCase;
use Wm\WmPackage\Jobs\App\ReindexAppContentsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Nova\EcPoi as PackageEcPoiResource;
use Wm\WmPackage\Nova\EcTrack as PackageEcTrackResource;
use Wm\WmPackage\Nova\Layer as PackageLayerResource;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Il salvataggio delle Resource del package dall'endpoint vero di Nova (oc:8910).
 *
 * Sta qui e non in uno shard perché queste Resource sono del package: un test che le protegge
 * da un solo shard lascia scoperti tutti gli altri. Vale anche per la sezione delle tassonomie
 * sulle schede EC, che compone il package (`EcTrack::fields()`, `EcPoi::fields()`) e che lo
 * shard eredita: che uno shard la richiami per conto suo non la rende sua.
 *
 * Esiste perché un difetto è sfuggito: una Resource Nova non è logica, è una dichiarazione che
 * il framework assembla, e i test che ne estraggono i pezzi — le `rules` di un campo date a
 * `Validator` — non provano che il montaggio avvenga. Un riferimento a una costante inesistente
 * passava quei test e rompeva ogni salvataggio dalla scheda.
 *
 * `Tests\TestCase` è quello del consumer: questi test fanno richieste HTTP a Nova, che è montata
 * dallo shard, quindi si lanciano da lì (`vendor/bin/pest wm-package/tests/Feature/Nova/...`) e
 * non dal package.
 */
uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    // Le Resource con Images arrivano a StorageService: senza un disco finto il salvataggio
    // muore sulla configurazione S3, non su cio' che si sta provando.
    config([
        'filesystems.disks.s3.region' => 'eu-west-1',
        'filesystems.disks.wmfe.region' => 'eu-west-1',
        // I job unici del salvataggio prendono il lock con uniqueVia() su Redis: in test
        // l'estensione phpredis non c'e', e senza questa riga esce «Class "Redis" not found».
        'cache.stores.redis.driver' => 'array',
        'database.redis.client' => 'predis',
    ]);
    Storage::fake('wmfe');
    Storage::fake('s3');

    RolesAndPermissionsService::seedDatabase();
    $this->user = User::factory()->create();
    $this->user->assignRole('Administrator');

    // Il bbox serve ai Layer: senza, il salvataggio si ferma con un errore d'uso.
    $this->testApp = App::factory()->createQuietly([
        'map_bbox' => '[10.39637,43.71683,10.52729,43.84512]',
    ]);

    $suffix = uniqid();
    $this->routeType = TaxonomyTerm::create([
        'identifier' => "route-type-{$suffix}",
        'name' => ['it' => 'Tipo percorso'],
        'single_choice' => true,
    ]);
    $this->trail = TaxonomyTerm::create([
        'parent_id' => $this->routeType->id,
        'identifier' => "route-type-trail-{$suffix}",
        'name' => ['it' => 'Sentiero'],
    ]);
    $this->walk = TaxonomyTerm::create([
        'parent_id' => $this->routeType->id,
        'identifier' => "route-type-walk-{$suffix}",
        'name' => ['it' => 'Cammino'],
    ]);
    $this->testApp->taxonomies()->sync([$this->routeType->id]);

    $this->termAttribute = 'taxonomy_'.str_replace([':', '-'], '_', $this->routeType->identifier);
});

it('cambia l\'icona di una tassonomia dalla sua scheda', function () {
    $this->routeType->update(['icon' => 'txn-trekking']);

    $this->actingAs($this->user)
        ->putJson("/nova-api/taxonomy-terms/{$this->routeType->id}", [
            'identifier' => $this->routeType->identifier,
            'icon' => 'txn-family',
            'sort_order' => 0,
            'translations_name_it' => 'Tipo percorso',
        ])
        ->assertOk();

    expect($this->routeType->fresh()->icon)->toBe('txn-family');
});

it('crea una tassonomia nuova senza passare dal codice', function () {
    $identifier = 'surface-type-'.uniqid();

    $this->actingAs($this->user)
        ->postJson('/nova-api/taxonomy-terms', [
            'identifier' => $identifier,
            'sort_order' => 0,
            'translations_name_it' => 'Tipo di fondo',
        ])
        ->assertCreated();

    expect(TaxonomyTerm::query()->where('identifier', $identifier)->exists())->toBeTrue();
});

it('rifiuta l\'identifier di una tassonomia con un punto, che Elasticsearch espanderebbe', function () {
    $this->actingAs($this->user)
        ->postJson('/nova-api/taxonomy-terms', [
            'identifier' => 'con.punto',
            'sort_order' => 0,
            'translations_name_it' => 'Con punto',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('identifier');
});

it('salva un termine: il formato dell\'identifier non vale per lui', function () {
    // Un termine porta l'identifier che gli da' la sorgente, punti compresi: e' un valore, non
    // una chiave dell'indice.
    $this->actingAs($this->user)
        ->putJson("/nova-api/taxonomy-terms/{$this->trail->id}", [
            'parent' => $this->routeType->id,
            'identifier' => 'IT.A',
            'sort_order' => 3,
            'translations_name_it' => 'Sentiero',
        ])
        ->assertOk();

    expect($this->trail->fresh())
        ->identifier->toBe('IT.A')
        ->sort_order->toBe(3);
});

it('salva i filtri per termine sulla scheda di un Layer', function () {
    $layer = Layer::factory()->createQuietly(['app_id' => $this->testApp->id]);

    $this->actingAs($this->user)
        ->putJson("/nova-api/layers/{$layer->id}", [
            'app' => $this->testApp->id,
            'appOwner' => $this->testApp->id,
            'translations_name_it' => 'Sentieri',
            // Su un layer la scelta e' sempre multipla, anche per una tassonomia a scelta
            // singola: «sentieri o cammini» deve essere possibile.
            $this->termAttribute => $this->trail->id.','.$this->walk->id,
        ])
        ->assertOk();

    expect($layer->fresh()->taxonomyTerms()->pluck('taxonomy_terms.id')->sort()->values()->all())
        ->toBe(collect([$this->trail->id, $this->walk->id])->sort()->values()->all());
});

it('scrive la tassonomia sulla relazione del Layer, cosi il filtro per famiglia funziona', function () {
    $layer = Layer::factory()->createQuietly(['app_id' => $this->testApp->id]);

    $this->actingAs($this->user)
        ->putJson("/nova-api/layers/{$layer->id}", [
            'app' => $this->testApp->id,
            'appOwner' => $this->testApp->id,
            'translations_name_it' => 'Sentieri',
            $this->termAttribute => (string) $this->trail->id,
        ])
        ->assertOk();

    expect(DB::table('taxonomy_termables')
        ->where('termable_type', $layer->getMorphClass())
        ->where('termable_id', $layer->id)
        ->value('parent_id'))->toBe($this->routeType->id);
});

it('accende le tassonomie per tipo di contenuto dalla scheda di un App', function () {
    $other = TaxonomyTerm::create([
        'identifier' => 'surface-type-'.uniqid(),
        'name' => ['it' => 'Fondo'],
    ]);

    // La stessa tassonomia sulle EC Track di quest'app, l'altra solo sugli EC Poi.
    $this->actingAs($this->user)
        ->putJson("/nova-api/apps/{$this->testApp->id}", [
            ...appPayload($this->testApp),
            'taxonomies_ec_track' => json_encode([$this->routeType->id]),
            'taxonomies_ec_poi' => json_encode([$other->id]),
        ])
        ->assertOk();

    $models = $this->testApp->fresh()->taxonomies()->get()
        ->mapWithKeys(fn ($t) => [$t->identifier => json_decode((string) $t->pivot->models, true)]);

    expect($models[$this->routeType->identifier])->toBe(['EcTrack'])
        ->and($models[$other->identifier])->toBe(['EcPoi']);
});

it('una tassonomia scelta in tutti e due gli elenchi vale per entrambi', function () {
    $this->actingAs($this->user)
        ->putJson("/nova-api/apps/{$this->testApp->id}", [
            ...appPayload($this->testApp),
            'taxonomies_ec_track' => json_encode([$this->routeType->id]),
            'taxonomies_ec_poi' => json_encode([$this->routeType->id]),
        ])
        ->assertOk();

    // «Tutti i contenuti» si scrive come `models` nullo, che è anche il valore che porta una
    // tassonomia accesa da fuori: una sola rappresentazione, non due.
    expect($this->testApp->fresh()->taxonomies()->first()->pivot->models)->toBeNull();
});

it('togliere una tassonomia da un elenco la lascia nell\'altro', function () {
    $this->testApp->syncTaxonomiesForModel('EcTrack', [$this->routeType->id]);
    $this->testApp->syncTaxonomiesForModel('EcPoi', [$this->routeType->id]);

    // Resta accesa, ma solo sugli EC Poi.
    $this->testApp->syncTaxonomiesForModel('EcTrack', []);

    $pivot = $this->testApp->fresh()->taxonomies()->first()->pivot;
    expect(json_decode((string) $pivot->models, true))->toBe(['EcPoi']);
});

it('toglierla da tutti e due gli elenchi la spegne', function () {
    // Nel beforeEach è accesa per tutti i contenuti: qui la si restringe alle EC Track.
    $this->testApp->syncTaxonomiesForModel('EcTrack', [$this->routeType->id]);
    $this->testApp->syncTaxonomiesForModel('EcPoi', []);

    $this->testApp->syncTaxonomiesForModel('EcTrack', []);

    expect($this->testApp->fresh()->taxonomies()->count())->toBe(0);
});

/**
 * I campi che la scheda di un'App chiede comunque, oltre a quello che il test sta provando.
 *
 * @return array<string, mixed>
 */
function appPayload(App $app): array
{
    return [
        'sku' => $app->sku,
        'name' => 'App di prova',
        'default_language' => 'it',
        'start_end_icons_min_zoom' => 10,
        'ref_on_track_min_zoom' => 10,
    ];
}

it('salva il tipo percorso sulla scheda di un sentiero', function () {
    $track = EcTrack::factory()->createQuietly([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'properties' => [],
    ]);

    $this->actingAs($this->user)
        ->putJson("/nova-api/ec-tracks/{$track->id}", [
            'app' => $this->testApp->id,
            'user' => $this->user->id,
            $this->termAttribute => (string) $this->trail->id,
        ])
        ->assertOk();

    expect($track->fresh()->terms($this->routeType->identifier)->pluck('id')->all())
        ->toBe([$this->trail->id]);
});

it('cambia il tipo percorso di un sentiero e non lascia il vecchio', function () {
    $track = EcTrack::factory()->createQuietly([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'properties' => [],
    ]);
    $track->taxonomyTerms()->attach($this->trail->id);

    $this->actingAs($this->user)
        ->putJson("/nova-api/ec-tracks/{$track->id}", [
            'app' => $this->testApp->id,
            'user' => $this->user->id,
            $this->termAttribute => (string) $this->walk->id,
        ])
        ->assertOk();

    expect($track->fresh()->terms($this->routeType->identifier)->pluck('id')->all())
        ->toBe([$this->walk->id]);
});

it('salva il tipo percorso anche sulla scheda di un POI', function () {
    // `properties` come array: la colonna porta due campi traducibili per path
    // (`properties->description`), e su una stringa il riempimento di Nova non regge.
    $poi = EcPoi::factory()->createQuietly([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'name' => ['it' => 'Fonte'],
        'properties' => [],
    ]);

    $this->actingAs($this->user)
        ->putJson("/nova-api/ec-pois/{$poi->id}", [
            'app' => $this->testApp->id,
            'user' => $this->user->id,
            'translations_name_it' => 'Fonte',
            $this->termAttribute => (string) $this->trail->id,
        ])
        ->assertOk();

    expect($poi->fresh()->terms($this->routeType->identifier)->pluck('id')->all())
        ->toBe([$this->trail->id]);
});

it('una tassonomia accesa sulle sole EC Track non compare sulla scheda di un EC Poi', function () {
    // L'accensione per app dice dove una tassonomia esiste, l'ambito a cosa si applica.
    // Nel beforeEach è accesa per tutti i contenuti: qui la si restringe alle EC Track.
    $this->testApp->syncTaxonomiesForModel('EcTrack', [$this->routeType->id]);
    $this->testApp->syncTaxonomiesForModel('EcPoi', []);

    $track = EcTrack::factory()->createQuietly([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id, 'properties' => [],
    ]);
    $poi = EcPoi::factory()->createQuietly([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id,
        'name' => ['it' => 'Fonte'], 'properties' => [],
    ]);

    expect(taxonomyAttributesOf(new PackageEcTrackResource($track)))->toContain($this->termAttribute)
        ->and(taxonomyAttributesOf(new PackageEcPoiResource($poi)))->not->toContain($this->termAttribute);
});

it('accesa per tutti i contenuti, una tassonomia vale per EC Track e EC Poi', function () {
    $track = EcTrack::factory()->createQuietly([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id, 'properties' => [],
    ]);
    $poi = EcPoi::factory()->createQuietly([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id,
        'name' => ['it' => 'Fonte'], 'properties' => [],
    ]);

    expect(taxonomyAttributesOf(new PackageEcTrackResource($track)))->toContain($this->termAttribute)
        ->and(taxonomyAttributesOf(new PackageEcPoiResource($poi)))->toContain($this->termAttribute);
});

it('su un Layer la tassonomia resta anche se è accesa sulle sole EC Track', function () {
    // Lì le tassonomie sono filtri che scelgono i contenuti, e un layer può raccoglierne di
    // tipi diversi: limitarle al modello della scheda non avrebbe senso.
    $this->testApp->syncTaxonomiesForModel('EcTrack', [$this->routeType->id]);
    $layer = Layer::factory()->createQuietly(['app_id' => $this->testApp->id]);

    expect(taxonomyAttributesOf(new PackageLayerResource($layer)))->toContain($this->termAttribute);
});

/**
 * Gli attributi dei campi dentro la sezione delle tassonomie di una Resource.
 *
 * @return array<int, string>
 */
function taxonomyAttributesOf(object $resource): array
{
    $request = NovaRequest::create('/nova-api/x', 'GET');
    $attributes = [];
    foreach ($resource->fields($request) as $field) {
        if ($field instanceof TabsGroup && $field->attribute === $resource::taxonomySectionKey()) {
            foreach ($field->data ?? [] as $inner) {
                $attributes[] = (string) ($inner->attribute ?? '');
            }
        }
    }

    return $attributes;
}

it('cambiare le tassonomie accese fa reindicizzare i contenuti dell\'app', function () {
    // `terms`, nel documento di una traccia, porta solo le tassonomie accese sull'app: senza un
    // reindex l'indice resta indietro, e la ricerca filtra su una classificazione che in Nova
    // non c'è più.
    Bus::fake();

    $this->actingAs($this->user)
        ->putJson("/nova-api/apps/{$this->testApp->id}", [
            ...appPayload($this->testApp),
            'taxonomies_ec_track' => json_encode([$this->routeType->id]),
            'taxonomies_ec_poi' => json_encode([]),
        ])
        ->assertOk();

    Bus::assertDispatched(
        ReindexAppContentsJob::class,
        fn (ReindexAppContentsJob $job) => $job->appId === $this->testApp->id
    );
});

it('salvare l\'app senza cambiare le tassonomie non fa reindicizzare niente', function () {
    // Il tab ha due campi che scrivono uno dopo l'altro: il primo lascia uno stato intermedio
    // che sembra un cambiamento. Il job parte lo stesso, ma porta con sé la firma di prima e,
    // girando dopo il commit, si accorge che lo stato definitivo è identico ed esce.
    $before = ReindexAppContentsJob::taxonomiesSignature($this->testApp);

    $this->actingAs($this->user)
        ->putJson("/nova-api/apps/{$this->testApp->id}", [
            ...appPayload($this->testApp),
            'taxonomies_ec_track' => json_encode([$this->routeType->id]),
            'taxonomies_ec_poi' => json_encode([$this->routeType->id]),
        ])
        ->assertOk();

    expect(ReindexAppContentsJob::taxonomiesSignature($this->testApp->fresh()))->toBe($before);
});

it('la firma cambia quando cambia l\'ambito, non solo l\'accensione', function () {
    $before = ReindexAppContentsJob::taxonomiesSignature($this->testApp);

    // Resta accesa, ma solo sulle EC Track: per l'indice è un cambiamento, perché `terms`
    // dipende da quali tassonomie valgono per quel contenuto.
    $this->testApp->syncTaxonomiesForModel('EcPoi', []);

    expect(ReindexAppContentsJob::taxonomiesSignature($this->testApp->fresh()))->not->toBe($before);
});

it('il lock del reindex scade, così un worker interrotto non lo blocca per sempre (oc:8910)', function () {
    // `UniqueLock::acquire()` legge `$job->uniqueFor ?? 0`, e con 0 il lock Redis nasce senza
    // TTL: un worker che muore a metà lo lascerebbe lì, e nessun reindex di quell'app verrebbe
    // più accodato. Gli altri job unici del package dichiarano tutti una scadenza.
    $job = new ReindexAppContentsJob($this->testApp->id);

    expect($job->uniqueFor)->toBeGreaterThan(0)
        ->and($job->uniqueId())->toBe('reindex_app_contents_'.$this->testApp->id);
});

it('su una tassonomia a scelta singola ne tiene una sola, anche da una richiesta a mano (oc:8910)', function () {
    // Il campo nel browser è a scelta singola, ma il vincolo si ferma lì: non c'è nulla nel
    // database, e una richiesta costruita a mano manderebbe due id. «La difficoltà di
    // accessibilità è una sola per sentiero» cadrebbe senza che niente lo segnali.
    $track = EcTrack::factory()->createQuietly([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'osmid' => null,
    ]);

    $this->actingAs($this->user)
        ->putJson("/nova-api/ec-tracks/{$track->id}", [
            'app' => $this->testApp->id,
            'user' => $this->user->id,
            $this->termAttribute => $this->trail->id.','.$this->walk->id,
        ])
        ->assertOk();

    expect($track->fresh()->terms($this->routeType->identifier)->pluck('id')->all())
        ->toBe([$this->trail->id]);
});

it('su una tassonomia a scelta multipla li tiene tutti (oc:8910)', function () {
    $this->routeType->update(['single_choice' => false]);
    $track = EcTrack::factory()->createQuietly([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'osmid' => null,
    ]);

    $this->actingAs($this->user)
        ->putJson("/nova-api/ec-tracks/{$track->id}", [
            'app' => $this->testApp->id,
            'user' => $this->user->id,
            $this->termAttribute => $this->trail->id.','.$this->walk->id,
        ])
        ->assertOk();

    expect($track->fresh()->terms($this->routeType->identifier)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->trail->id, $this->walk->id])->sort()->values()->all());
});
