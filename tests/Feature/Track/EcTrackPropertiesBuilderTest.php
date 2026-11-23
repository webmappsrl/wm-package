<?php

declare(strict_types=1);

require_once __DIR__.'/../../Concerns/InsertsGeometryFixtures.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Enums\WmfeTrackVersion;
use Wm\WmPackage\Http\Resources\EcTrackPropertiesBuilder;
use Wm\WmPackage\Http\Resources\TrackVersions\LegacyTrackPropertiesTransformation;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Tests\Concerns\InsertsGeometryFixtures;

/**
 * oc:8997 — il punto unico che dal modello produce le properties di ogni versione.
 *
 * La forma corrente si costruisce per intero; le versioni precedenti sono trasformazioni
 * applicate a ritroso (R4 dell'overview, metodo di versionamento di Stripe).
 */
uses(DatabaseTransactions::class, InsertsGeometryFixtures::class);

beforeEach(function () {
    config(['wm-package.shard_name' => 'test-shard']);
    Storage::fake('wmfe');
});

function builderTrack(array $extraProperties = [], bool $withTaxonomies = true): EcTrack
{
    $app = App::factory()->createQuietly();
    $where = TaxonomyWhere::create(['name' => ['it' => 'Zona '.uniqid()]]);

    $properties = $extraProperties;
    if ($withTaxonomies) {
        $properties['taxonomy_where'] = [(string) $where->id => ['name' => ['it' => 'Zona'], 'admin_level' => 8]];
    }

    $track = EcTrack::find(test()->insertEcTrackWithGeometry($app, $properties));

    if ($withTaxonomies) {
        $track->taxonomyWheres()->attach($where->id);
        $track->taxonomyActivities()->attach(
            TaxonomyActivity::create(['name' => ['it' => 'Attività '.uniqid()]])->id
        );
    }

    return $track->fresh();
}

it('la forma corrente ha le sole taxonomies, senza le tre forme vecchie', function () {
    $properties = (new EcTrackPropertiesBuilder(builderTrack()))->current();

    expect($properties)->toHaveKey('taxonomies')
        ->and($properties)->not->toHaveKey('taxonomy')
        ->and($properties)->not->toHaveKey('taxonomy_where')
        ->and($properties)->not->toHaveKey('taxonomyWheres');
});

it('restituisce solo le versioni chieste, nell\'ordine chiesto', function () {
    $builder = new EcTrackPropertiesBuilder(builderTrack());

    expect(array_keys($builder->build([WmfeTrackVersion::Legacy, WmfeTrackVersion::V1])))
        ->toBe(['legacy', 'v1'])
        ->and(array_keys($builder->build([WmfeTrackVersion::V1])))->toBe(['v1']);
});

it('la versione senza numero differisce dalla corrente per le sole chiavi vecchie delle tassonomie', function () {
    $both = (new EcTrackPropertiesBuilder(builderTrack()))
        ->build([WmfeTrackVersion::Legacy, WmfeTrackVersion::V1]);

    expect(array_keys(array_diff_key($both['legacy'], $both['v1'])))
        ->toEqualCanonicalizing(['taxonomy', 'taxonomy_where', 'taxonomyWheres'])
        ->and(array_diff_key($both['v1'], $both['legacy']))->toBe([])
        ->and($both['legacy']['taxonomies'])->toEqual($both['v1']['taxonomies']);
});

it('calcola i dati condivisi una volta sola: la seconda build non interroga il database', function () {
    $builder = new EcTrackPropertiesBuilder(builderTrack());
    $builder->build([WmfeTrackVersion::Legacy, WmfeTrackVersion::V1]);

    DB::enableQueryLog();
    $builder->build([WmfeTrackVersion::Legacy, WmfeTrackVersion::V1]);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
});

it('non espone la chiave interna del backup delle zone in nessuna versione', function () {
    // `_taxonomy_where_backup` lo scrive TaxonomyWhereResyncService: è un dato di servizio, non
    // deve uscire in un file pubblico. Prima di oc:8997 lo toglieva applyTaxonomyWhereDisplay(),
    // che ora gira solo per la versione senza numero.
    $track = builderTrack(['_taxonomy_where_backup' => ['81' => ['name' => ['it' => 'Vecchia']]]]);

    $both = (new EcTrackPropertiesBuilder($track))
        ->build([WmfeTrackVersion::Legacy, WmfeTrackVersion::V1]);

    expect($both['v1'])->not->toHaveKey('_taxonomy_where_backup')
        ->and($both['legacy'])->not->toHaveKey('_taxonomy_where_backup');
});

it('rifiuta una versione per cui non sa produrre le properties, invece di ripiegare in silenzio', function () {
    // Il giorno che nascerà la v2, chiedere la v1 senza che esista la sua trasformazione deve
    // fallire: scrivere la forma corrente sotto il path di un'altra versione sarebbe un file
    // sbagliato che nessuno vede. È la stessa scelta di WmfeTrackVersion::active().
    $builder = new EcTrackPropertiesBuilder(builderTrack());
    $missing = array_values(array_filter(
        WmfeTrackVersion::cases(),
        fn (WmfeTrackVersion $v) => $v !== WmfeTrackVersion::current() && $v !== WmfeTrackVersion::Legacy
    ));

    if ($missing === []) {
        // Oggi ogni versione conosciuta sa produrre le proprie properties: il caso si presenterà
        // con la v2. Il controllo resta a guardia di quel giorno.
        expect(array_keys($builder->build(WmfeTrackVersion::cases())))
            ->toEqualCanonicalizing(array_column(WmfeTrackVersion::cases(), 'value'));

        return;
    }

    expect(fn () => $builder->build($missing))->toThrow(LogicException::class);
});

it('il builder dichiara quale versione costruisce, e deve essere la corrente', function () {
    // La guardia che si accorge della v2: aggiungere un caso all'enum senza scrivere qui la forma
    // nuova farebbe etichettare la forma vecchia con il nome della versione nuova, e il file
    // finirebbe sotto `v2/` senza che nessuno se ne accorga.
    expect(EcTrackPropertiesBuilder::buildsVersion())->toBe(WmfeTrackVersion::current());
});

it('la trasformazione verso la versione senza numero dichiara da dove parte', function () {
    expect(LegacyTrackPropertiesTransformation::appliesTo())->toBe(WmfeTrackVersion::Legacy)
        ->and(LegacyTrackPropertiesTransformation::appliesFrom())->toBe(WmfeTrackVersion::current());
});
