<?php

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Nova\TrailHistoryRenderer;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * La storia del sentiero letta tutta insieme: eventi di stato ed eventi dei
 * codici catastali in un'unica sequenza ordinata (oc:8989, R13).
 *
 * I termini si creano con un identifier unico, come vuole la trappola dei test
 * del package: un identifier fisso gia' presente fa scattare il controllo dei
 * doppioni. Il nome e' lo stesso in tutte le lingue perche' l'asserzione
 * guardi una stringa vera e non la stringa vuota del locale mancante.
 */
beforeEach(function () {
    runTaxonomyStubs();
    runTrailRegistryStubs();

    $suffix = uniqid();

    $this->family = TaxonomyTerm::create([
        'identifier' => "walkability-status-{$suffix}",
        'name' => ['it' => 'Percorribilità', 'en' => 'Percorribilità'],
        'single_choice' => true,
    ]);

    $this->walkable = TaxonomyTerm::create([
        'identifier' => "walkability-status-walkable-{$suffix}",
        'name' => ['it' => 'Percorribile', 'en' => 'Percorribile'],
        'parent_id' => $this->family->id,
    ]);

    $this->notWalkable = TaxonomyTerm::create([
        'identifier' => "walkability-status-not-walkable-{$suffix}",
        'name' => ['it' => 'Non percorribile', 'en' => 'Non percorribile'],
        'parent_id' => $this->family->id,
    ]);

    // `createQuietly`: gli observer della traccia escono verso lo storage.
    // `osmid` nullo perche' la factory lo valorizza a caso nel 70% dei casi.
    $this->track = EcTrack::factory()->createQuietly(['osmid' => null]);
    $this->user = User::factory()->create();
});

function makeTrailHistoryStatusEvent(array $attributes): void
{
    TrailRegistryClasses::statusEvent()::create(array_merge([
        'source' => 'manual',
    ], $attributes));
}

function makeTrailHistoryCodeEvent(int $codeId, array $attributes = []): void
{
    TrailRegistryClasses::event()::create(array_merge([
        'trail_registry_code_id' => $codeId,
        'from_status' => null,
        'to_status' => TrailCodeStatus::Assigned,
        'reason' => 'istanza approvata',
        'user_id' => null,
        'created_at' => now(),
    ], $attributes));
}

it('mette gli eventi di stato e quelli del codice in un_unica sequenza per data decrescente', function () {
    $codeId = makeCode([
        'status' => TrailCodeStatus::Assigned,
        'ec_track_id' => $this->track->id,
        'number' => 35,
    ]);

    makeTrailHistoryCodeEvent($codeId, [
        'to_status' => TrailCodeStatus::Assigned,
        'reason' => 'istanza approvata',
        'created_at' => now()->subDays(10),
    ]);

    makeTrailHistoryStatusEvent([
        'ec_track_id' => $this->track->id,
        'taxonomy_id' => $this->family->id,
        'from_term_id' => null,
        'to_term_id' => $this->walkable->id,
        'description' => 'allineamento iniziale',
        'source' => 'migration',
        'created_at' => now()->subDays(5),
    ]);

    makeTrailHistoryStatusEvent([
        'ec_track_id' => $this->track->id,
        'taxonomy_id' => $this->family->id,
        'from_term_id' => $this->walkable->id,
        'to_term_id' => $this->notWalkable->id,
        'description' => 'albero caduto al km 3',
        'user_id' => $this->user->id,
        'source' => 'manual',
        'created_at' => now()->subDay(),
    ]);

    $rows = TrailHistoryRenderer::rowsFor($this->track);

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'kind'))->toBe([
            TrailHistoryRenderer::KIND_STATUS,
            TrailHistoryRenderer::KIND_STATUS,
            TrailHistoryRenderer::KIND_CODE,
        ]);

    expect($rows[0]['from'])->toBe('Percorribile')
        ->and($rows[0]['to'])->toBe('Non percorribile')
        ->and($rows[0]['subject'])->toBe('Percorribilità')
        ->and($rows[0]['who'])->toBe($this->user->name)
        ->and($rows[0]['note'])->toBe('albero caduto al km 3');

    // L'utente manca quasi sempre: al suo posto la riga dice da dove viene.
    expect($rows[1]['from'])->toBeNull()
        ->and($rows[1]['to'])->toBe('Percorribile')
        ->and($rows[1]['who'])->toBe(__('Initial alignment'));

    expect($rows[2]['kind'])->toBe(TrailHistoryRenderer::KIND_CODE)
        ->and($rows[2]['subject'])->toBe('ZNUB535')
        ->and($rows[2]['from'])->toBeNull()
        ->and($rows[2]['to'])->toBe(TrailCodeStatus::Assigned->value)
        ->and($rows[2]['note'])->toBe('istanza approvata');

    // Le date scendono: e' la sequenza unica che R13 chiede.
    $timestamps = array_map(fn (array $row) => $row['at']->getTimestamp(), $rows);
    expect($timestamps)->toBe([$timestamps[0], $timestamps[1], $timestamps[2]])
        ->and($timestamps[0])->toBeGreaterThan($timestamps[1])
        ->and($timestamps[1])->toBeGreaterThan($timestamps[2]);
});

it('mostra gli eventi di tutti i codici che il sentiero ha avuto, non solo di quello attivo', function () {
    // Un numero si libera e se ne assegna un altro: due righe di codice per lo
    // stesso sentiero. L'indice unico e' parziale, quindi la riga liberata
    // resta in tabella con il suo `ec_track_id`.
    $oldCodeId = makeCode([
        'status' => TrailCodeStatus::Released,
        'ec_track_id' => $this->track->id,
        'number' => 35,
    ]);

    $newCodeId = makeCode([
        'status' => TrailCodeStatus::Assigned,
        'ec_track_id' => $this->track->id,
        'number' => 40,
    ]);

    makeTrailHistoryCodeEvent($oldCodeId, [
        'from_status' => TrailCodeStatus::Assigned,
        'to_status' => TrailCodeStatus::Released,
        'reason' => 'numero sostituito',
        'created_at' => now()->subDays(3),
    ]);

    makeTrailHistoryCodeEvent($newCodeId, [
        'to_status' => TrailCodeStatus::Assigned,
        'reason' => 'numero nuovo',
        'created_at' => now()->subDay(),
    ]);

    $rows = TrailHistoryRenderer::rowsFor($this->track);

    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'subject'))->toBe(['ZNUB540', 'ZNUB535'])
        ->and($rows[1]['from'])->toBe(TrailCodeStatus::Assigned->value)
        ->and($rows[1]['to'])->toBe(TrailCodeStatus::Released->value);
});

it('non porta nella storia di un sentiero gli eventi di un codice senza sentiero', function () {
    // `ec_track_id` e' nullable: un codice riservato non ha ancora un
    // sentiero, e le sue righe non sono la storia di nessuna traccia.
    $reservedCodeId = makeCode(['status' => TrailCodeStatus::Reserved, 'number' => 50]);

    expect(DB::table('trail_registry_codes')->where('id', $reservedCodeId)->value('ec_track_id'))
        ->toBeNull();

    makeTrailHistoryCodeEvent($reservedCodeId, [
        'to_status' => TrailCodeStatus::Reserved,
        'reason' => 'numero riservato',
    ]);

    expect(TrailHistoryRenderer::rowsFor($this->track))->toBe([]);
});

it('non mescola le storie di due sentieri diversi', function () {
    $otherTrack = EcTrack::factory()->createQuietly(['osmid' => null]);

    makeTrailHistoryStatusEvent([
        'ec_track_id' => $otherTrack->id,
        'taxonomy_id' => $this->family->id,
        'to_term_id' => $this->walkable->id,
        'created_at' => now(),
    ]);

    $codeId = makeCode([
        'status' => TrailCodeStatus::Assigned,
        'ec_track_id' => $otherTrack->id,
        'number' => 60,
    ]);
    makeTrailHistoryCodeEvent($codeId);

    expect(TrailHistoryRenderer::rowsFor($this->track))->toBe([])
        ->and(TrailHistoryRenderer::rowsFor($otherTrack))->toHaveCount(2);
});

it('senza niente da raccontare dice che la storia e vuota', function () {
    expect(TrailHistoryRenderer::rowsFor($this->track))->toBe([])
        ->and(TrailHistoryRenderer::render($this->track))->toContain('Nessun evento registrato');
});

it('rende ogni riga in HTML con la sua provenienza', function () {
    $codeId = makeCode([
        'status' => TrailCodeStatus::Assigned,
        'ec_track_id' => $this->track->id,
        'number' => 35,
    ]);
    makeTrailHistoryCodeEvent($codeId, ['created_at' => now()->subDays(2)]);

    makeTrailHistoryStatusEvent([
        'ec_track_id' => $this->track->id,
        'taxonomy_id' => $this->family->id,
        'to_term_id' => $this->walkable->id,
        'description' => 'rilevato in campo',
        'user_id' => $this->user->id,
        'created_at' => now(),
    ]);

    $html = TrailHistoryRenderer::render($this->track);

    expect($html)->toContain('ZNUB535')
        ->toContain('Percorribile')
        ->toContain('rilevato in campo')
        ->toContain($this->user->name);
});
