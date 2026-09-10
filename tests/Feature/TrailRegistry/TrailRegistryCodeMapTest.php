<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

beforeEach(function () {
    runTrailRegistryStubs();
    Bus::fake();
});

it('mostra il settore, il sentiero e l istanza quando ci sono tutti', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    // E' il caso reale di un codice nato da una domanda e poi approvato:
    // l'istanza resta legata anche dopo, per sapere da dove il codice viene.
    // L'helper crea l'istanza solo per i codici riservati, quindi qui va
    // passata a mano.
    $applicationId = DB::table('trail_applications')->insertGetId([
        'user_id' => makeTrailRegistryTestUser(),
        'source' => 'api',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $codeId = makeCode([
        'status' => TrailCodeStatus::Assigned,
        'taxonomy_where_id' => $sectorId,
        'trail_application_id' => $applicationId,
    ]);
    $code = TrailRegistryCode::findOrFail($codeId);

    // Le geometrie vanno date qui: l'helper crea le righe ma non le geometrie.
    foreach ([['trail_applications', $code->trail_application_id], ['ec_tracks', $code->ec_track_id]] as [$table, $id]) {
        DB::statement(
            "UPDATE {$table} SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?",
            ['MULTILINESTRING Z((1 1 0, 2 2 0))', $id],
        );
    }

    $collection = $code->fresh()->getFeatureCollectionMap();

    expect($collection['type'])->toBe('FeatureCollection')
        ->and($collection['features'])->toHaveCount(3);

    $tooltips = array_map(fn (array $f) => $f['properties']['tooltip'], $collection['features']);

    expect($tooltips[0])->toContain('ZNUB5')
        ->and($tooltips[1])->toContain('Sentiero')
        ->and($tooltips[2])->toContain('Istanza');
});

it('su un codice riservato mostra settore e istanza, non il sentiero', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $codeId = makeCode(['status' => TrailCodeStatus::Reserved, 'taxonomy_where_id' => $sectorId]);
    $code = TrailRegistryCode::findOrFail($codeId);

    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z((1 1 0, 2 2 0))', $code->trail_application_id],
    );

    $features = $code->fresh()->getFeatureCollectionMap()['features'];

    expect($features)->toHaveCount(2);

    $tooltips = array_map(fn (array $f) => $f['properties']['tooltip'], $features);

    expect(implode(' ', $tooltips))->not->toContain('Sentiero');
});

it('non compone una feature per una geometria assente', function () {
    // Nessuna geometria in nessuna delle tre tabelle: la mappa esce vuota
    // invece di sollevare, cosi' la scheda del codice si apre comunque.
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    DB::statement('UPDATE taxonomy_wheres SET geometry = NULL WHERE id = ?', [$sectorId]);

    $code = TrailRegistryCode::findOrFail(
        makeCode(['status' => TrailCodeStatus::Reserved, 'taxonomy_where_id' => $sectorId]),
    );

    expect($code->getFeatureCollectionMap()['features'])->toBeEmpty();
});

it('da a ogni feature un colore proprio e un link cliccabile', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $code = TrailRegistryCode::findOrFail(
        makeCode(['status' => TrailCodeStatus::Assigned, 'taxonomy_where_id' => $sectorId]),
    );

    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z((1 1 0, 2 2 0))', $code->ec_track_id],
    );

    $features = $code->fresh()->getFeatureCollectionMap()['features'];
    $colors = array_map(fn (array $f) => $f['properties']['strokeColor'], $features);

    expect($colors)->toBe(array_unique($colors));

    foreach ($features as $feature) {
        expect($feature['properties']['link'])->toStartWith('http');
    }
});

it('il settore ha un riempimento, le tracce no', function () {
    $sectorId = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');
    $code = TrailRegistryCode::findOrFail(
        makeCode(['status' => TrailCodeStatus::Assigned, 'taxonomy_where_id' => $sectorId]),
    );

    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z((1 1 0, 2 2 0))', $code->ec_track_id],
    );

    $features = $code->fresh()->getFeatureCollectionMap()['features'];

    expect($features[0]['properties'])->toHaveKey('fillColor')
        ->and($features[1]['properties'])->not->toHaveKey('fillColor');
});

it('mostra anche gli altri settori attraversati, con la percentuale', function () {
    // Il tracciato corre per lo piu' in ZNUB5 e sconfina in ZNUB9: il primo e'
    // quello scelto, il secondo va mostrato in tono minore — e' cosi' che si
    // vede *perche'* il prefisso e' quello.
    $chosen = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 8 10, 8 0, 0 0))');
    makeSector('ZNUB9', 'POLYGON((8 0, 8 10, 10 10, 10 0, 8 0))');

    $code = TrailRegistryCode::findOrFail(
        makeCode(['status' => TrailCodeStatus::Assigned, 'taxonomy_where_id' => $chosen]),
    );

    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z((0.5 5 0, 9.5 5 0))', $code->ec_track_id],
    );

    $features = $code->fresh()->getFeatureCollectionMap()['features'];
    $sectors = array_values(array_filter($features, fn (array $f) => isset($f['properties']['taxonomy_where_id'])));

    expect($sectors)->toHaveCount(2);

    // Lo scelto e' disegnato per **ultimo**, cosi' resta sopra agli altri e non
    // viene coperto: sulla mappa le feature si sovrappongono nell'ordine
    // dell'elenco.
    $chosen = end($sectors);
    $other = $sectors[0];

    expect($chosen['properties']['tooltip'])->toContain('ZNUB5')
        ->and($chosen['properties']['tooltip'])->toContain('%')
        ->and($chosen['properties']['tooltip'])->toContain('scelto')
        ->and($other['properties']['tooltip'])->toContain('ZNUB9')
        ->and($other['properties']['tooltip'])->not->toContain('scelto');

    // Lo scelto e' marcato, l'altro e' in grigio ma comunque visibile.
    expect($chosen['properties']['strokeWidth'])->toBeGreaterThan($other['properties']['strokeWidth']);
    expect($other['properties']['fillColor'])->toBe('rgba(100, 116, 139, 0.15)');
});

it('con un solo settore attraversato non aggiunge nulla', function () {
    $chosen = makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $code = TrailRegistryCode::findOrFail(
        makeCode(['status' => TrailCodeStatus::Assigned, 'taxonomy_where_id' => $chosen]),
    );

    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z((1 1 0, 2 2 0))', $code->ec_track_id],
    );

    $sectors = array_filter(
        $code->fresh()->getFeatureCollectionMap()['features'],
        fn (array $f) => isset($f['properties']['taxonomy_where_id']),
    );

    expect($sectors)->toHaveCount(1);
});

it('mostra il settore scelto anche se il tracciato non lo attraversa piu', function () {
    // Caso reale possibile su un codice storico: il sentiero e' stato
    // ritracciato dopo l'assegnazione e ora cade altrove. Il settore che il
    // codice dichiara va mostrato lo stesso — la sua assenza dall'elenco degli
    // attraversati e' essa stessa l'anomalia da vedere.
    $chosen = makeSector('ZNUB5', 'POLYGON((0 0, 0 1, 1 1, 1 0, 0 0))');
    makeSector('ZNUB9', 'POLYGON((50 50, 50 60, 60 60, 60 50, 50 50))');

    $code = TrailRegistryCode::findOrFail(
        makeCode(['status' => TrailCodeStatus::Assigned, 'taxonomy_where_id' => $chosen]),
    );

    DB::statement(
        'UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z((51 51 0, 52 52 0))', $code->ec_track_id],
    );

    $sectors = array_values(array_filter(
        $code->fresh()->getFeatureCollectionMap()['features'],
        fn (array $f) => isset($f['properties']['taxonomy_where_id']),
    ));

    $tooltips = implode(' | ', array_column(array_column($sectors, 'properties'), 'tooltip'));

    expect($tooltips)->toContain('ZNUB5')->toContain('ZNUB9');
});
