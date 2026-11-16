<?php

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailStatusEventSource;
use Wm\WmPackage\TrailRegistry\Nova\WalkabilityHistoryRenderer;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * La cronologia della percorribilita' come elenco leggibile nel detail di Nova
 * (oc:8989, R6).
 *
 * I termini si creano con un identifier unico, come vuole la trappola dei test
 * del package: un identifier fisso gia' presente fa scattare il controllo dei
 * doppioni di TaxonomyObserver.
 */
beforeEach(function () {
    runTaxonomyStubs();
    runTrailRegistryStubs();

    $suffix = uniqid();

    config(['wm-package.features.trail_registry.walkability_taxonomy' => "walkability-status-{$suffix}"]);

    $this->family = TaxonomyTerm::create([
        'identifier' => "walkability-status-{$suffix}",
        'name' => ['it' => 'Percorribilità', 'en' => 'Walkability'],
        'single_choice' => true,
    ]);

    $this->unknown = TaxonomyTerm::create([
        'identifier' => "walkability-status-unknown-{$suffix}",
        'name' => ['it' => 'Non nota', 'en' => 'Unknown'],
        'parent_id' => $this->family->id,
    ]);

    $this->walkable = TaxonomyTerm::create([
        'identifier' => "walkability-status-walkable-{$suffix}",
        'name' => ['it' => 'Percorribile', 'en' => 'Walkable'],
        'parent_id' => $this->family->id,
    ]);

    $this->closed = TaxonomyTerm::create([
        'identifier' => "walkability-status-closed-{$suffix}",
        'name' => ['it' => 'Chiuso', 'en' => 'Closed'],
        'parent_id' => $this->family->id,
    ]);

    // `createQuietly`: gli observer della traccia escono verso lo storage.
    // `osmid` nullo perche' la factory lo valorizza a caso nel 70% dei casi.
    $this->track = EcTrack::factory()->createQuietly(['osmid' => null]);
    $this->user = User::factory()->create(['name' => 'Maria Pintus']);
});

/**
 * Scrive un evento di percorribilita' sulla traccia del test, con i valori
 * ricorrenti gia' impostati.
 */
function walkabilityEvent(array $attributes): void
{
    TrailRegistryClasses::statusEvent()::create(array_merge([
        'ec_track_id' => test()->track->id,
        'taxonomy_id' => test()->family->id,
        'from_term_id' => null,
        'description' => null,
        'user_id' => null,
        'source' => 'import',
    ], $attributes));
}

it("produce una riga per evento, dalla piu' recente", function () {
    walkabilityEvent([
        'to_term_id' => $this->unknown->id,
        'source' => 'migration',
        'created_at' => '2026-01-10 09:00:00',
    ]);
    walkabilityEvent([
        'from_term_id' => $this->unknown->id,
        'to_term_id' => $this->walkable->id,
        'source' => 'import',
        'created_at' => '2026-02-11 10:30:00',
    ]);
    walkabilityEvent([
        'from_term_id' => $this->walkable->id,
        'to_term_id' => $this->closed->id,
        'description' => 'albero caduto al km 3',
        'user_id' => $this->user->id,
        'source' => 'manual',
        'created_at' => '2026-03-12 16:45:00',
    ]);

    $html = WalkabilityHistoryRenderer::render($this->track);

    // Tre righe di dati piu' l'intestazione.
    expect(substr_count($html, '<tr'))->toBe(4);

    $positions = [
        strpos($html, '12/03/2026 16:45'),
        strpos($html, '11/02/2026 10:30'),
        strpos($html, '10/01/2026 09:00'),
    ];

    expect($positions[0])->not->toBeFalse()
        ->and($positions[1])->toBeGreaterThan($positions[0])
        ->and($positions[2])->toBeGreaterThan($positions[1]);
});

it('mostra il nome tradotto dei due stati, la nota e il nome di chi ha scritto', function () {
    walkabilityEvent([
        'from_term_id' => $this->walkable->id,
        'to_term_id' => $this->closed->id,
        'description' => 'albero caduto al km 3',
        'user_id' => $this->user->id,
        'source' => 'manual',
        'created_at' => '2026-03-12 16:45:00',
    ]);

    $html = WalkabilityHistoryRenderer::render($this->track);

    expect($html)->toContain($this->walkable->name)
        ->and($html)->toContain($this->closed->name)
        ->and($html)->toContain('albero caduto al km 3')
        ->and($html)->toContain('Maria Pintus');
});

it("scrive la provenienza, non un trattino, quando l'utente manca", function () {
    walkabilityEvent([
        'to_term_id' => $this->walkable->id,
        'source' => 'import',
        'created_at' => '2026-02-11 10:30:00',
    ]);

    $html = WalkabilityHistoryRenderer::render($this->track);

    expect($html)->toContain(TrailStatusEventSource::Import->label());
});

it("lascia vuoto lo stato di partenza quando l'evento non ne ha uno", function () {
    walkabilityEvent([
        'to_term_id' => $this->unknown->id,
        'source' => 'migration',
        'created_at' => '2026-01-10 09:00:00',
    ]);

    $html = WalkabilityHistoryRenderer::render($this->track);

    expect($html)->toContain('—')
        ->and($html)->toContain($this->unknown->name);
});

it('non mostra gli eventi di altre classificazioni', function () {
    $other = TaxonomyTerm::create([
        'identifier' => 'validation-status-'.uniqid(),
        'name' => ['it' => 'Validazione', 'en' => 'Validation'],
    ]);

    walkabilityEvent([
        'taxonomy_id' => $other->id,
        'to_term_id' => $this->walkable->id,
        'created_at' => '2026-02-11 10:30:00',
    ]);

    expect(WalkabilityHistoryRenderer::render($this->track))
        ->toContain('Nessun passaggio registrato');
});

it('non mostra gli eventi di un altro sentiero', function () {
    $otherTrack = EcTrack::factory()->createQuietly(['osmid' => null]);

    TrailRegistryClasses::statusEvent()::create([
        'ec_track_id' => $otherTrack->id,
        'taxonomy_id' => $this->family->id,
        'to_term_id' => $this->walkable->id,
        'source' => 'import',
        'created_at' => '2026-02-11 10:30:00',
    ]);

    expect(WalkabilityHistoryRenderer::render($this->track))
        ->toContain('Nessun passaggio registrato');
});

it("dice che non c'e' storia quando non ci sono eventi", function () {
    expect(WalkabilityHistoryRenderer::render($this->track))
        ->toContain('Nessun passaggio registrato');
});
