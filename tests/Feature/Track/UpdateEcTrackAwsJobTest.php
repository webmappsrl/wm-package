<?php

declare(strict_types=1);

require_once __DIR__.'/../../Concerns/InsertsGeometryFixtures.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Enums\WmfeTrackVersion;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\Tests\Concerns\InsertsGeometryFixtures;

/**
 * oc:8997 — il job scrive un file per versione attiva, e se una scrittura fallisce fallisce lui.
 */
uses(DatabaseTransactions::class, InsertsGeometryFixtures::class);

beforeEach(function () {
    config(['wm-package.shard_name' => 'test-shard']);
    Storage::fake('wmfe');
});

function jobTrack(): EcTrack
{
    $app = App::factory()->createQuietly();
    $where = TaxonomyWhere::create(['name' => ['it' => 'Zona '.uniqid()]]);

    // Con le tassonomie valorizzate: senza, le tre chiavi vecchie mancherebbero da sole e
    // l'asserzione sulla v1 non proverebbe niente.
    $track = EcTrack::find(test()->insertEcTrackWithGeometry($app, [
        'ref' => 'SS-1',
        'taxonomy_where' => [(string) $where->id => ['name' => ['it' => 'Zona'], 'admin_level' => 8]],
    ]));
    $track->taxonomyWheres()->attach($where->id);
    $track->taxonomyActivities()->attach(
        TaxonomyActivity::create(['name' => ['it' => 'Attività '.uniqid()]])->id
    );

    return $track->fresh();
}

it('scrive un file per ogni versione attiva', function () {
    config(['wm-package.wmfe_track_versions' => 'legacy,v1']);
    $track = jobTrack();

    (new UpdateEcTrackAwsJob($track))->handle(new StorageService);

    Storage::disk('wmfe')->assertExists("/test-shard/tracks/{$track->id}.json");
    Storage::disk('wmfe')->assertExists("/test-shard/tracks/v1/{$track->id}.json");
});

it('scrive solo le versioni dichiarate', function () {
    config(['wm-package.wmfe_track_versions' => 'v1']);
    $track = jobTrack();

    (new UpdateEcTrackAwsJob($track))->handle(new StorageService);

    Storage::disk('wmfe')->assertMissing("/test-shard/tracks/{$track->id}.json");
    Storage::disk('wmfe')->assertExists("/test-shard/tracks/v1/{$track->id}.json");
});

it('fallisce se una scrittura fallisce, invece di restare verde con due file divergenti', function () {
    config(['wm-package.wmfe_track_versions' => 'legacy,v1']);
    $track = jobTrack();

    $storage = new class extends StorageService
    {
        public function storeTrack(int $trackId, $contents, WmfeTrackVersion $version): string|false
        {
            return $version === WmfeTrackVersion::Legacy ? 'ok' : false;
        }
    };

    expect(fn () => (new UpdateEcTrackAwsJob($track))->handle($storage))
        ->toThrow(RuntimeException::class);
});

it('scrive lo stesso involucro in tutte le versioni', function () {
    config(['wm-package.wmfe_track_versions' => 'legacy,v1']);
    $track = jobTrack();

    (new UpdateEcTrackAwsJob($track))->handle(new StorageService);

    $legacy = json_decode((string) Storage::disk('wmfe')->get("/test-shard/tracks/{$track->id}.json"), true);
    $v1 = json_decode((string) Storage::disk('wmfe')->get("/test-shard/tracks/v1/{$track->id}.json"), true);

    expect($v1['type'])->toBe($legacy['type'])
        ->and($v1['geometry'])->toEqual($legacy['geometry'])
        ->and($v1['properties']['id'])->toBe($legacy['properties']['id']);
});

it('nella versione v1 le tassonomie hanno la sola forma taxonomies, che nel file senza versione ci sono tutte', function () {
    config(['wm-package.wmfe_track_versions' => 'legacy,v1']);
    $track = jobTrack();

    (new UpdateEcTrackAwsJob($track))->handle(new StorageService);

    $legacy = json_decode((string) Storage::disk('wmfe')->get("/test-shard/tracks/{$track->id}.json"), true);
    $v1 = json_decode((string) Storage::disk('wmfe')->get("/test-shard/tracks/v1/{$track->id}.json"), true);

    // Nel file senza versione ci sono tutte e quattro: è questo che rende l'assenza nella v1 una
    // prova, invece di un caso in cui le chiavi mancavano da sole.
    expect($legacy['properties'])->toHaveKeys(['taxonomies', 'taxonomy', 'taxonomy_where', 'taxonomyWheres'])
        ->and($v1['properties'])->toHaveKey('taxonomies')
        ->and($v1['properties'])->not->toHaveKey('taxonomy')
        ->and($v1['properties'])->not->toHaveKey('taxonomy_where')
        ->and($v1['properties'])->not->toHaveKey('taxonomyWheres');
});
