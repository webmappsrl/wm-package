<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Commands\TrailRegistryNormalizeCommand;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeOrigin;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\Nova\AnomalyDetailRenderer;

beforeEach(function () {
    runTrailRegistryStubs();
    $this->app[Kernel::class]->registerCommand(new TrailRegistryNormalizeCommand);
    Bus::fake();
});

/**
 * Si filtra solo il nome nullo, non i valori "vuoti": `properties => []`
 * significa «questa traccia non ha proprieta'», e va scritto cosi' — con
 * array_filter() sparirebbe, e la traccia erediterebbe il `ref` casuale del
 * factory, cioe' l'opposto di quello che il test vuole provare.
 */
function trackWith(array $properties, string $wkt, ?string $name = null): EcTrack
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

it('registra il sentiero rimasto senza numero perche quel codice e di un altro', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    // Due geometrie DIVERSE dentro lo stesso settore: il codice resta
    // gia' assegnato ad altri (stessa posizione nel registro) senza che le tracce siano
    // anche geometrie duplicate, che e' un'anomalia indipendente. Mescolare
    // le due cose indebolirebbe il test proprio dove serve severo.
    $winner = trackWith(['ref' => 'Z-NU-B-535'], 'MULTILINESTRING Z((1 1 0, 2 2 0))');
    $loser = trackWith(['ref' => '535'], 'MULTILINESTRING Z((3 3 0, 4 4 0))');

    $this->artisan('wm-package:trail-registry-normalize --force')->assertExitCode(0);

    $anomaly = TrailRegistryAnomaly::where('ec_track_id', $loser->id)->firstOrFail();

    expect($anomaly->type)->toBe(TrailRegistryAnomalyType::CodiceGiaAssegnato)
        // La riga deve dire *chi* quel codice lo porta gia': e' cio' che serve
        // al gestore per decidere a chi spetta.
        ->and($anomaly->related_ec_track_id)->toBe($winner->id);

    // Chi tiene il numero non finisce nella lista di lavoro, per nessun motivo.
    expect(TrailRegistryAnomaly::where('ec_track_id', $winner->id)->exists())->toBeFalse();
});

it('rileva il codice gia di altri anche a registro gia popolato', function () {
    // Il caso che conta in esercizio: alla seconda esecuzione tutte le
    // tracce sono gia' registrate, quindi l'esito del giro non dice piu'
    // nulla sui conflitti. L'anomalia si legge dallo stato del registro.
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $winner = trackWith(['ref' => 'Z-NU-B-535'], 'MULTILINESTRING Z((1 1 0, 2 2 0))');
    $loser = trackWith(['ref' => '535'], 'MULTILINESTRING Z((3 3 0, 4 4 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');
    $this->artisan('wm-package:trail-registry-normalize --force');

    $anomaly = TrailRegistryAnomaly::where('ec_track_id', $loser->id)->firstOrFail();

    expect($anomaly->type)->toBe(TrailRegistryAnomalyType::CodiceGiaAssegnato)
        ->and($anomaly->related_ec_track_id)->toBe($winner->id);
});

it('non suggerisce alcun codice per un itinerario, che un codice non ce l ha', function () {
    // Nome reale: il prefisso `C100T` non e' un codice di sentiero, e senza
    // parentesi finali non viene raccolto.
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $track = trackWith([], 'MULTILINESTRING Z((1 1 0, 2 2 0))', 'C100T - Via Catalana');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryAnomaly::where('ec_track_id', $track->id)->exists())->toBeFalse();
});

it('prende il codice dalle parentesi finali, non dai riferimenti ai bivi nel nome', function () {
    // Nome reale: i numeri nel mezzo sono bivi, il codice vero e' in fondo.
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $track = trackWith([], 'MULTILINESTRING Z((1 1 0, 2 2 0))', 'Bivio 104-104A - Su Mutrucone - B. 105-104A (G 504A)');

    $this->artisan('wm-package:trail-registry-normalize --force');

    // Il codice si legge e si registra: «G 104A» -> settore 5 dalla
    // geometria, coda 04 variante A. Se avesse preso il bivio (104) sarebbe
    // finito su un'altra posizione.
    $code = TrailRegistryCode::where('ec_track_id', $track->id)->firstOrFail();

    expect($code->code)->toBe('ZNUB504A');
});

it('registra il settore discordante', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    // Il codice dice settore 3, la geometria dice 5.
    $track = trackWith(['ref' => '332'], 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');

    $anomaly = TrailRegistryAnomaly::where('ec_track_id', $track->id)->firstOrFail();

    // Nel contesto stanno i DATI, non la frase: il codice come e' scritto
    // nella fonte, e quello che la piattaforma comporrebbe dalla geometria.
    expect($anomaly->type)->toBe(TrailRegistryAnomalyType::SettoreDiscordante)
        ->and($anomaly->context['raw_code'])->toBe('332')
        ->and($anomaly->context['proposed_code'])->toBe('ZNUB532');

    // E la frase si compone in lettura, con dentro entrambi.
    $html = AnomalyDetailRenderer::render($anomaly);

    expect($html)->toContain('332')->toContain('ZNUB532');
});

it('il codice scritto nel nome non e un anomalia: si legge e si registra', function () {
    // Nelle anomalie vanno i sentieri rimasti SENZA numero che dovrebbero
    // averlo. Questo il numero ce l'ha: il fatto che stesse nel nome invece
    // che nella proprieta' dedicata lo dice la provenienza nel registro.
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $track = trackWith([], 'MULTILINESTRING Z((1 1 0, 2 2 0))', 'Bivio Loddue - Sant Anna (G 510)');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryAnomaly::where('ec_track_id', $track->id)->exists())->toBeFalse();

    $code = TrailRegistryCode::where('ec_track_id', $track->id)->firstOrFail();

    expect($code->code)->toBe('ZNUB510')
        ->and($code->origin)->toBe(TrailCodeOrigin::Nome);
});

it('non registra nulla per una traccia senza codice ne nella proprieta ne nel nome', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $track = trackWith([], 'MULTILINESTRING Z((1 1 0, 2 2 0))', 'Sentiero del monte');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryAnomaly::where('ec_track_id', $track->id)->exists())->toBeFalse();
});

it('registra le due tracce con la stessa geometria', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';

    $first = trackWith(['ref' => 'Z-NU-B-535'], $wkt);
    $second = trackWith(['ref' => 'T-535'], $wkt);

    $this->artisan('wm-package:trail-registry-normalize --force');

    $duplicates = TrailRegistryAnomaly::where('type', TrailRegistryAnomalyType::GeometriaDuplicata->value)->get();

    expect($duplicates)->toHaveCount(2)
        ->and($duplicates->pluck('ec_track_id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

it('non da un numero a nessuna delle due tracce con la stessa geometria', function () {
    // Nel registro stanno solo i codici senza anomalie. Finche' non si sa
    // quale delle due tracce sia quella buona, nessuna prende un numero:
    // assegnarlo a entrambe, o all'una a caso, e' peggio che non assegnarlo.
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';
    trackWith(['ref' => 'Z-NU-B-535'], $wkt);
    trackWith(['ref' => 'T-536'], $wkt);

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::count())->toBe(0);
});

it('non registra il codice di una traccia con settore discordante', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    // Il codice dice settore 3, la geometria dice 5.
    $track = trackWith(['ref' => '332'], 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::where('ec_track_id', $track->id)->exists())->toBeFalse();
    expect(TrailRegistryAnomaly::where('ec_track_id', $track->id)->firstOrFail()->type)
        ->toBe(TrailRegistryAnomalyType::SettoreDiscordante);
});

it('registra la traccia che non ricade in alcun settore', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');
    $track = trackWith(['ref' => 'Z-NU-B-535'], 'MULTILINESTRING Z((50 50 0, 51 51 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryAnomaly::where('ec_track_id', $track->id)->firstOrFail()->type)
        ->toBe(TrailRegistryAnomalyType::FuoriDaOgniSettore);
});

it('sostituisce le anomalie a ogni esecuzione invece di accumularle', function () {
    // E' il senso della lista: sistemata la scheda alla fonte e rifatto
    // l'import, l'anomalia deve sparire da se'. Se le righe si accumulassero,
    // il gestore lavorerebbe su una lista che non si accorcia mai.
    makeSector('ZNUB5', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');
    $track = trackWith(['ref' => 'Z-NU-B-535'], 'MULTILINESTRING Z((50 50 0, 51 51 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');
    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryAnomaly::count())->toBe(1);

    // Sistemata la geometria, l'anomalia sparisce.
    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z((0.2 0.2 0, 0.3 0.3 0))', $track->id]
    );

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryAnomaly::where('type', TrailRegistryAnomalyType::FuoriDaOgniSettore->value)->count())->toBe(0);
});

it('in prova a vuoto non scrive nemmeno le anomalie', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');
    trackWith(['ref' => 'Z-NU-B-535'], 'MULTILINESTRING Z((50 50 0, 51 51 0))');

    $this->artisan('wm-package:trail-registry-normalize --dry-run')->assertExitCode(0);

    expect(TrailRegistryAnomaly::count())->toBe(0);
});

it('legge il codice dalla proprieta indicata in configurazione', function () {
    config(['wm-package.features.trail_registry.legacy_code_property' => 'codice_storico']);

    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    trackWith(['codice_storico' => 'Z-NU-B-535'], 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');

    expect(TrailRegistryCode::count())->toBe(1);
});

it('mostra chi quel codice ce l ha gia, con il link alla fonte', function () {
    config(['wm-package.features.trail_registry.source_url_property' => 'forestas.url']);

    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $assignee = trackWith(
        ['ref' => 'Z-NU-B-535', 'forestas' => ['url' => 'https://esempio.test/sentieri/primo']],
        'MULTILINESTRING Z((1 1 0, 2 2 0))',
        'Primo sentiero',
    );
    $contested = trackWith(['ref' => '535'], 'MULTILINESTRING Z((3 3 0, 4 4 0))', 'Secondo sentiero');

    $this->artisan('wm-package:trail-registry-normalize --force');

    $anomaly = TrailRegistryAnomaly::where('ec_track_id', $contested->id)->firstOrFail();

    expect($anomaly->context['code'])->toBe('ZNUB535')
        ->and($anomaly->context['assigned_to']['id'])->toBe($assignee->id)
        ->and($anomaly->context['assigned_to']['name'])->toBe('Primo sentiero')
        ->and($anomaly->context['assigned_to']['url'])->toBe('https://esempio.test/sentieri/primo');

    $html = AnomalyDetailRenderer::render($anomaly);

    expect($html)->toContain('ZNUB535')
        ->toContain('Primo sentiero')
        ->toContain('https://esempio.test/sentieri/primo');
});

it('nella geometria duplicata elenca tutti i gemelli, non solo il primo', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';

    $first = trackWith(['ref' => 'Z-NU-B-535'], $wkt, 'Uno');
    $second = trackWith(['ref' => 'T-536'], $wkt, 'Due');
    $third = trackWith(['ref' => 'T-537'], $wkt, 'Tre');

    $this->artisan('wm-package:trail-registry-normalize --force');

    $anomaly = TrailRegistryAnomaly::where('ec_track_id', $first->id)
        ->where('type', TrailRegistryAnomalyType::GeometriaDuplicata->value)
        ->firstOrFail();

    expect(array_column($anomaly->context['twins'], 'id'))
        ->toBe([$second->id, $third->id]);

    expect(AnomalyDetailRenderer::render($anomaly))
        ->toContain('Due')
        ->toContain('Tre');
});

it('porta il collegamento alla scheda alla fonte accanto al nome del sentiero', function () {
    config(['wm-package.features.trail_registry.source_url_property' => 'forestas.url']);

    makeSector('ZNUB5', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');
    $track = trackWith(
        ['ref' => 'Z-NU-B-535', 'forestas' => ['url' => 'https://esempio.test/sentieri/lontano']],
        'MULTILINESTRING Z((50 50 0, 51 51 0))',
    );

    $this->artisan('wm-package:trail-registry-normalize --force');

    $anomaly = TrailRegistryAnomaly::where('ec_track_id', $track->id)->firstOrFail();

    // Il collegamento non e' piu' una riga del dettaglio: sta accanto al
    // nome, come icona, cosi' ogni nome porta sia qui dentro sia alla fonte.
    expect(AnomalyDetailRenderer::trackLink($anomaly->context['track']))
        ->toContain('https://esempio.test/sentieri/lontano')
        ->toContain('<svg');
});

it('propone il primo numero libero per un codice illeggibile, senza assegnarlo', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    // Occupa lo 00, cosi' il primo libero e' lo 01 e si vede che il numero
    // proposto tiene conto di cio' che e' gia' nel registro.
    trackWith(['ref' => 'Z-NU-B-500'], 'MULTILINESTRING Z((1 1 0, 2 2 0))');
    $unreadable = trackWith(['ref' => 'sentiero del monte'], 'MULTILINESTRING Z((3 3 0, 4 4 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');

    $anomaly = TrailRegistryAnomaly::where('ec_track_id', $unreadable->id)->firstOrFail();

    expect($anomaly->type)->toBe(TrailRegistryAnomalyType::CodiceIlleggibile)
        ->and($anomaly->context['raw_code'])->toBe('sentiero del monte')
        ->and($anomaly->context['proposed_code'])->toBe('ZNUB501');

    // Proposto, non assegnato: nel registro quel sentiero non c'e'.
    expect(TrailRegistryCode::where('ec_track_id', $unreadable->id)->exists())->toBeFalse();
});

it('disegna sulla mappa il sentiero senza numero e quello che il numero ce l ha', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $holder = trackWith(['ref' => 'Z-NU-B-535'], 'MULTILINESTRING Z((1 1 0, 2 2 0))', 'Chi ha il numero');
    $without = trackWith(['ref' => '535'], 'MULTILINESTRING Z((3 3 0, 4 4 0))', 'Chi resta senza');

    $this->artisan('wm-package:trail-registry-normalize --force');

    $anomaly = TrailRegistryAnomaly::where('ec_track_id', $without->id)->firstOrFail();
    $tooltips = array_column(array_column($anomaly->getFeatureCollectionMap()['features'], 'properties'), 'tooltip');

    // Le tracce si identificano per NOME: un ruolo come «senza numero»
    // varrebbe per ogni riga di questa lista e non direbbe quale sia quale.
    expect($tooltips)->toContain('Chi resta senza')
        ->and($tooltips)->toContain('Chi ha il numero');
});

it('sul settore discordante disegna sia il settore vero sia quello dichiarato dal codice', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    // Il settore dichiarato dal codice esiste, ma sta altrove: è proprio il
    // confronto fra i due poligoni a dire quanto è grosso l'errore.
    makeSector('ZNUB3', 'POLYGON((40 40, 40 50, 50 50, 50 40, 40 40))');

    $track = trackWith(['ref' => '332'], 'MULTILINESTRING Z((1 1 0, 2 2 0))');

    $this->artisan('wm-package:trail-registry-normalize --force');

    $anomaly = TrailRegistryAnomaly::where('ec_track_id', $track->id)->firstOrFail();
    $tooltips = array_column(array_column($anomaly->getFeatureCollectionMap()['features'], 'properties'), 'tooltip');

    expect($tooltips)->toContain('Settore ZNUB3 — dichiarato dal codice');
    expect(implode(' | ', $tooltips))->toContain('Settore ZNUB5');
});

it('distingue i gemelli per colore e spessore, non potendo distinguerli per posizione', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $wkt = 'MULTILINESTRING Z((1 1 0, 2 2 0))';

    $first = trackWith(['ref' => 'Z-NU-B-535'], $wkt, 'Uno');
    trackWith(['ref' => 'T-536'], $wkt, 'Due');

    $this->artisan('wm-package:trail-registry-normalize --force');

    $anomaly = TrailRegistryAnomaly::where('ec_track_id', $first->id)
        ->where('type', TrailRegistryAnomalyType::GeometriaDuplicata->value)
        ->firstOrFail();

    $features = $anomaly->getFeatureCollectionMap()['features'];
    $twin = collect($features)->firstWhere('properties.tooltip', 'Due');
    $subject = collect($features)->firstWhere('properties.tooltip', 'Uno');

    // Le due tracce coincidono al pixel: le rende entrambe visibili solo il
    // contrasto bianco su rosso, con la bianca piu' sottile.
    expect($twin)->not->toBeNull()
        ->and($twin['properties']['strokeColor'])->toBe('rgba(255, 255, 255, 1)')
        ->and($twin['properties']['strokeWidth'])->toBeLessThan($subject['properties']['strokeWidth']);

    // E la bianca va disegnata DOPO: sotto sparirebbe del tutto, e la mappa
    // mostrerebbe una linea sola dove invece ce ne sono due.
    $tooltips = array_column(array_column($features, 'properties'), 'tooltip');

    expect(array_search('Due', $tooltips, true))
        ->toBeGreaterThan(array_search('Uno', $tooltips, true));
});
