<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Commands\TrailRegistryNormalizeCommand;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

beforeEach(function () {
    runTrailRegistryStubs();
    $this->app[Kernel::class]->registerCommand(new TrailRegistryNormalizeCommand);
});

/**
 * Una traccia con `ref` e geometria reale: il nome non collide con gli
 * helper degli altri file del dominio, che Pest carica nello stesso processo.
 */
function sourceTestTrack(string $ref, string $wkt): EcTrack
{
    $track = EcTrack::factory()->createQuietly(['properties' => ['ref' => $ref], 'osmid' => null]);

    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        [$wkt, $track->id]
    );

    return $track->refresh();
}

it('accetta anomalie senza traccia e di tipo dello shard', function () {
    DB::table('trail_registry_anomalies')->insert([
        'ec_track_id' => null, 'type' => 'shard_tipo_esempio',
        'source' => 'shard', 'context' => '{}', 'created_at' => now(),
    ]);

    expect(TrailRegistryAnomaly::fromSource('shard')->count())->toBe(1);
});

it('la provenienza non ha default: chi scrive deve dichiararla', function () {
    DB::table('trail_registry_anomalies')->insert([
        'ec_track_id' => null, 'type' => 'x', 'context' => '{}', 'created_at' => now(),
    ]);
})->throws(QueryException::class);

it('il normalize non tocca le anomalie di altra provenienza', function () {
    DB::table('trail_registry_anomalies')->insert([
        'ec_track_id' => null, 'type' => 'shard_tipo_esempio',
        'source' => 'shard', 'context' => '{}', 'created_at' => now(),
    ]);

    $this->artisan('wm-package:trail-registry-normalize', ['--force' => true])->assertSuccessful();

    expect(TrailRegistryAnomaly::fromSource('shard')->count())->toBe(1);
});

it('il normalize cancella e riscrive le anomalie del catasto, lasciando quelle dello shard', function () {
    // EcTrackObserver accoderebbe job verso servizi esterni.
    Bus::fake();

    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $holder = sourceTestTrack('Z-NU-B-535', 'MULTILINESTRING Z((1 1 0, 2 2 0))');
    $contested = sourceTestTrack('535', 'MULTILINESTRING Z((3 3 0, 4 4 0))');

    // Una riga del catasto rimasta da un'esecuzione precedente: il normalize
    // la deve togliere, non accumularla accanto a quelle nuove.
    $staleId = DB::table('trail_registry_anomalies')->insertGetId([
        'ec_track_id' => $holder->id, 'type' => TrailRegistryAnomalyType::GeometriaDuplicata->value,
        'source' => TrailRegistryAnomaly::SOURCE_CATASTO, 'context' => '{}', 'created_at' => now(),
    ]);
    $shardId = DB::table('trail_registry_anomalies')->insertGetId([
        'ec_track_id' => null, 'type' => 'shard_tipo_esempio',
        'source' => 'shard', 'context' => '{}', 'created_at' => now(),
    ]);

    $this->artisan('wm-package:trail-registry-normalize', ['--force' => true])->assertSuccessful();

    expect(DB::table('trail_registry_anomalies')->where('id', $staleId)->exists())->toBeFalse();
    expect(DB::table('trail_registry_anomalies')->where('id', $shardId)->exists())->toBeTrue();

    $rewritten = TrailRegistryAnomaly::fromSource(TrailRegistryAnomaly::SOURCE_CATASTO)->get();

    expect($rewritten)->toHaveCount(1);
    expect($rewritten->first()->ec_track_id)->toBe($contested->id);
    expect($rewritten->first()->type)->toBe(TrailRegistryAnomalyType::CodiceGiaAssegnato);
});

it('il down della create rimuove la tabella', function () {
    $migration = require __DIR__.'/../../../database/migrations/trail_registry/zz_2026_09_10_000001_create_trail_registry_anomalies_table.php.stub';
    $migration->down();

    expect(Schema::hasTable('trail_registry_anomalies'))->toBeFalse();
});
