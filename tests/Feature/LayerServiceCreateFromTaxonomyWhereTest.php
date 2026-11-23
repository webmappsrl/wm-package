<?php

declare(strict_types=1);

use App\Models\EcTrack;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\Models\LayerService;

// Gira dallo shard (`vendor/bin/pest wm-package/tests/...`): il TestCase del package vuole il
// vendor del package, che uno shard non ha (vedi .claude/rules/test.md del package).
uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    Bus::fake();
    config(['cache.stores.redis.driver' => 'array']);
});

/** Un'area con un quadrato attorno a (9.05, 40.05), con le properties date. */
function areaWithProperties(array $properties): TaxonomyWhere
{
    $where = TaxonomyWhere::create([
        'name' => ['it' => 'Goceano', 'en' => 'Goceano'],
        'properties' => ['source' => 'test-'.uniqid()] + $properties,
    ]);

    DB::statement(
        "UPDATE taxonomy_wheres SET geometry = ST_GeomFromText('MULTIPOLYGON(((9 40, 9.1 40, 9.1 40.1, 9 40.1, 9 40)))', 4326) WHERE id = ?",
        [$where->id]
    );

    return $where->fresh();
}

// La logica di «Crea Layer» passa nel service, così l'import di forestas la usa (oc:9001).
it('creates the layer of an area with every translation of name, description and subtitle', function () {
    $app = App::factory()->createQuietly();
    $where = areaWithProperties([
        'description' => ['it' => 'Testo'],
        'subtitle' => ['it' => 'Sotto'],
    ]);

    $layer = LayerService::make()->createLayerFromTaxonomyWhere($where, $app);

    expect($layer->getTranslations('name'))->toBe(['it' => 'Goceano', 'en' => 'Goceano'])
        ->and($layer->properties['description']['it'] ?? null)->toBe('Testo')
        ->and($layer->properties['subtitle']['it'] ?? null)->toBe('Sotto')
        ->and($layer->app_id)->toBe($app->id)
        ->and($layer->user_id)->toBe($app->user_id)
        ->and($layer->taxonomyWheres()->pluck('taxonomy_wheres.id')->all())->toBe([$where->id]);
});

// Un testo semplice non dice la sua lingua: nome e descrizione devono finire nella stessa, quella
// corrente, come fa Spatie quando riceve una stringa.
it('puts a plain-text name and description in the same language, the current one', function () {
    app()->setLocale('en');
    $app = App::factory()->createQuietly();
    $where = areaWithProperties(['title' => 'Goceano', 'description' => 'Testo']);
    DB::statement("UPDATE taxonomy_wheres SET name = '{}' WHERE id = ?", [$where->id]);

    $layer = LayerService::make()->createLayerFromTaxonomyWhere($where->fresh(), $app);

    expect($layer->getTranslations('name'))->toBe(['en' => 'Goceano'])
        ->and($layer->properties['description'] ?? null)->toBe(['en' => 'Testo']);
});

it('leaves the layer empty, with its rank, when the pivot events are off', function () {
    $app = App::factory()->createQuietly();
    $where = areaWithProperties([]);

    $track = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
    DB::statement(
        "UPDATE ec_tracks SET geometry = ST_GeomFromText('LINESTRING Z(9.04 40.04 0, 9.06 40.06 0)', 4326) WHERE id = ?",
        [$track->id]
    );

    $layer = LayerService::make()->createLayerFromTaxonomyWhere($where, $app, withPivotEvents: false);

    // Vuoto, ma con il rank: gli eventi spenti sono solo quelli del collegamento all'area,
    // non quelli del Layer (`LayerObserver::creating()` imposta il rank).
    expect($layer->ecTracks()->count())->toBe(0)
        ->and($layer->fresh()->rank)->toBeGreaterThan(0);
});
