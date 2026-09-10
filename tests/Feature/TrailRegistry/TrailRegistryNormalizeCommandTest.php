<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Commands\TrailRegistryNormalizeCommand;

beforeEach(function () {
    runTrailRegistryStubs();

    $this->app[Kernel::class]->registerCommand(new TrailRegistryNormalizeCommand);

    // EcTrackObserver mette in coda una chain che include
    // UpdateEcTrack3DDemJob (chiamata reale a dem.maphub.it) ad ogni save:
    // qui interessa solo leggere geometrie gia' scritte, non il ciclo di vita
    // completo di un EcTrack — si fa il fake del Bus per non dipendere da un
    // servizio esterno reale nei test.
    Bus::fake();

    // App::factory()->create() (chiamata implicita di EcTrackFactory quando
    // non esiste ancora una App) fa scattare AppObserver::saved(), che tenta
    // di scrivere la config su storage e fallisce in questo ambiente di test
    // (shard_name non configurato) — stesso side-effect gia' documentato in
    // tests/Pest.php::makeCode(). Si crea l'App in anticipo, quietamente.
    DB::table('apps')->exists() || App::factory()->createQuietly();
});

it('non scrive nulla in prova a vuoto', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $track = EcTrack::factory()->create(['properties' => ['ref' => 'Z-NU-B-535']]);
    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z((1 1 0, 2 2 0))', $track->id]
    );

    $this->artisan('wm-package:trail-registry-normalize --dry-run')
        ->assertExitCode(0);

    expect(DB::table('trail_registry_codes')->count())->toBe(0);
});

// Fino al 09/09/2026 il comando si rifiutava di girare senza --dry-run,
// perche' la scrittura era fuori scope. Ora scrive, ma senza opzioni chiede
// conferma: la garanzia presidiata qui e' la stessa di prima — non si scrive
// nel registro per errore — solo espressa nel nuovo comportamento.
it('senza opzioni chiede conferma e non scrive se la si nega', function () {
    $this->artisan('wm-package:trail-registry-normalize')
        ->expectsConfirmation('Scrivere nel registro i codici letti? Nessuna opzione annulla senza scrivere.', 'no')
        ->assertExitCode(0);

    expect(DB::table('trail_registry_codes')->count())->toBe(0);
});

it('riporta le tracce la cui geometria non ricade in alcun settore', function () {
    $track = EcTrack::factory()->create(['properties' => ['ref' => '206']]);
    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z((50 50 0, 51 51 0))', $track->id]
    );

    $this->artisan('wm-package:trail-registry-normalize --dry-run')
        ->expectsOutputToContain('fuori da ogni settore: 1');
});

it('riporta le divergenze fra settore dedotto e settore scritto nel codice', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    // il codice dice settore 3, la geometria cade nel settore 5
    $track = EcTrack::factory()->create(['properties' => ['ref' => '332A']]);
    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z((1 1 0, 2 2 0))', $track->id]
    );

    $this->artisan('wm-package:trail-registry-normalize --dry-run')
        ->expectsOutputToContain('settore discordante: 1');
});

it('conta i doppioni dopo aver ricavato il settore, non prima', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    makeSector('ZNUG7', 'POLYGON((20 20, 20 30, 30 30, 30 20, 20 20))');

    // Stesso testo `700` ma settori diversi: NON e' un doppione.
    foreach ([['D 700', 'MULTILINESTRING Z((1 1 0, 2 2 0))'], ['T-700', 'MULTILINESTRING Z((21 21 0, 22 22 0))']] as [$ref, $wkt]) {
        $track = EcTrack::factory()->create(['properties' => ['ref' => $ref]]);
        DB::statement('UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?', [$wkt, $track->id]);
    }

    $this->artisan('wm-package:trail-registry-normalize --dry-run')
        ->expectsOutputToContain('doppioni reali: 0');
});
