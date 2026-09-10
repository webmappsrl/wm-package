<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Commands\TrailRegistryNormalizeCommand;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\Nova\AnomalyDetailRenderer;

/**
 * Il dominio deve funzionare su uno shard che non e' forestas: niente
 * `forestas.url`, niente parentesi nei nomi, settori importati da un'altra
 * sorgente, Resource Nova con chiavi diverse. Ogni presunzione che rientri
 * di nascosto fa fallire uno di questi test.
 */
beforeEach(function () {
    runTrailRegistryStubs();
    $this->app[Kernel::class]->registerCommand(new TrailRegistryNormalizeCommand);
    Bus::fake();
});

function shardTrack(array $properties, string $wkt, ?string $name = null): EcTrack
{
    $attributes = ['properties' => $properties];

    if ($name !== null) {
        $attributes['name'] = $name;
    }

    $track = EcTrack::factory()->createQuietly($attributes);

    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        [$wkt, $track->id]
    );

    return $track->refresh();
}

it('legge i settori dalla sorgente configurata, non da osm2cai', function () {
    config(['wm-package.features.trail_registry.sector_source' => 'catasto-regionale']);

    // Il settore arriva da un'altra sorgente: con il filtro fisso su osm2cai
    // non verrebbe mai trovato e ogni sentiero risulterebbe fuori settore.
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))', 'catasto-regionale');

    shardTrack(['ref' => 'Z-NU-B-535'], 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::firstOrFail()->code)->toBe('ZNUB535');
});

it('non cerca l indirizzo della fonte se il consumer non lo ha dichiarato', function () {
    config(['wm-package.features.trail_registry.source_url_property' => null]);

    makeSector('ZNUB5', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');
    $track = shardTrack(
        ['ref' => 'Z-NU-B-535', 'forestas' => ['url' => 'https://esempio.test/scheda']],
        'MULTILINESTRING Z((50 50 0, 51 51 0))',
    );

    $this->artisan('wm-package:trail-registry-normalize --force');

    $anomaly = TrailRegistryAnomaly::where('ec_track_id', $track->id)->firstOrFail();

    expect($anomaly->context['track']['url'])->toBeNull()
        ->and(AnomalyDetailRenderer::trackLink($anomaly->context['track']))->not->toContain('esempio.test');
});

it('non prova a indovinare codici nel nome se la convenzione non esiste', function () {
    config(['wm-package.features.trail_registry.name_code_pattern' => '']);

    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    shardTrack([], 'MULTILINESTRING Z((1 1 0, 2 2 0))', 'Nuscale (B 535)');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::count())->toBe(0);
});

it('riconosce la convenzione di un altra fonte quando la si dichiara', function () {
    // Qui il codice sta in coda dopo un trattino, non fra parentesi.
    config(['wm-package.features.trail_registry.name_code_pattern' => '/-\s*([A-Z]\s?\d{2,3}[A-Z]?)\s*$/']);

    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    shardTrack([], 'MULTILINESTRING Z((1 1 0, 2 2 0))', 'Sentiero del monte - B535');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::firstOrFail()->code)->toBe('ZNUB535');
});

it('indirizza i collegamenti con le chiavi Nova dichiarate dal consumer', function () {
    config(['wm-package.features.trail_registry.nova_uri_keys.ec_track' => 'sentieri']);

    expect(AnomalyDetailRenderer::trackLink(['id' => 7, 'name' => 'Sette', 'url' => null]))
        ->toContain('/resources/sentieri/7');
});
