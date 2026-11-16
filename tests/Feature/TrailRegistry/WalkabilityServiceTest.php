<?php

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailStatusEventSource;
use Wm\WmPackage\TrailRegistry\Exceptions\WalkabilityConflictException;
use Wm\WmPackage\TrailRegistry\Models\TrailStatusEvent;
use Wm\WmPackage\TrailRegistry\Services\WalkabilityService;

/**
 * L'unica via di scrittura della percorribilita' (oc:8989): stato ed evento
 * nella stessa transazione, e nessun altro percorso che cambi lo stato senza
 * lasciare storia.
 */
beforeEach(function () {
    runTaxonomyStubs();
    runTrailRegistryStubs();

    $suffix = uniqid();
    config(['wm-package.features.trail_registry.walkability_taxonomy' => "walkability-{$suffix}"]);

    $this->family = TaxonomyTerm::create([
        'identifier' => "walkability-{$suffix}",
        'name' => ['it' => 'Percorribilità'],
        'single_choice' => true,
    ]);

    foreach (['unknown', 'not-walkable', 'walkable'] as $position => $state) {
        $this->{str_replace('-', '_', $state)} = TaxonomyTerm::create([
            'identifier' => "walkability-{$suffix}-{$state}",
            'name' => ['it' => $state],
            'parent_id' => $this->family->id,
            'sort_order' => $position,
        ]);
    }

    $this->track = EcTrack::factory()->createQuietly(['osmid' => null]);
    $this->user = User::factory()->create();
    $this->service = app(WalkabilityService::class);
});

it('scrive lo stato e il suo evento insieme', function () {
    $event = $this->service->set(
        $this->track,
        $this->walkable->identifier,
        'guado ripristinato',
        $this->user->id,
        TrailStatusEventSource::Manual,
    );

    expect($this->service->current($this->track)->identifier)->toBe($this->walkable->identifier)
        ->and($event->to_term_id)->toBe($this->walkable->id)
        ->and($event->from_term_id)->toBeNull()
        ->and($event->description)->toBe('guado ripristinato')
        ->and($event->user_id)->toBe($this->user->id)
        ->and($event->source)->toBe(TrailStatusEventSource::Manual);
});

it('registra da quale stato si viene', function () {
    $this->service->set($this->track, $this->walkable->identifier, null, null, TrailStatusEventSource::Migration);
    $second = $this->service->set($this->track, $this->not_walkable->identifier, 'albero caduto al km 3', $this->user->id, TrailStatusEventSource::Manual);

    expect($second->from_term_id)->toBe($this->walkable->id)
        ->and($second->to_term_id)->toBe($this->not_walkable->id);
});

it('lascia un solo stato attaccato quando due scritture si susseguono', function () {
    $this->service->set($this->track, $this->walkable->identifier, null, null, TrailStatusEventSource::Import);
    $this->service->set($this->track, $this->not_walkable->identifier, null, null, TrailStatusEventSource::Manual);

    // `parent_id` sta sia in `taxonomy_terms` sia nel pivot: va qualificato.
    $attached = $this->track->taxonomyTerms()->where('taxonomy_terms.parent_id', $this->family->id)->count();

    expect($attached)->toBe(1)
        ->and($this->service->current($this->track)->identifier)->toBe($this->not_walkable->identifier);
});

it('rifiuta la scrittura se lo stato di partenza non è più quello', function () {
    $this->service->set($this->track, $this->walkable->identifier, null, null, TrailStatusEventSource::Import);

    expect(fn () => $this->service->set(
        $this->track,
        $this->not_walkable->identifier,
        null,
        $this->user->id,
        TrailStatusEventSource::Manual,
        $this->unknown->identifier,
    ))->toThrow(WalkabilityConflictException::class);

    expect($this->service->current($this->track)->identifier)->toBe($this->walkable->identifier)
        ->and(TrailStatusEvent::count())->toBe(1);
});

it('non scrive niente se lo stato non cambia', function () {
    $this->service->set($this->track, $this->walkable->identifier, null, null, TrailStatusEventSource::Import);
    $this->service->set($this->track, $this->walkable->identifier, null, null, TrailStatusEventSource::Import);

    expect(TrailStatusEvent::count())->toBe(1);
});

it('non lascia lo stato scritto se l_evento non si può scrivere', function () {
    // Un termine di un'altra famiglia: la scrittura deve fallire per intero,
    // senza lasciare il legame senza il suo evento.
    $alien = TaxonomyTerm::create([
        'identifier' => 'alien-'.uniqid(),
        'name' => ['it' => 'Altro'],
    ]);

    expect(fn () => $this->service->set($this->track, $alien->identifier, null, null, TrailStatusEventSource::Manual))
        ->toThrow(InvalidArgumentException::class);

    expect($this->track->taxonomyTerms()->count())->toBe(0)
        ->and(TrailStatusEvent::count())->toBe(0);
});
