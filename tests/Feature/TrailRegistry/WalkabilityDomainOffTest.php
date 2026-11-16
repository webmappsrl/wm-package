<?php

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\EcTrack as EcTrackResource;
use Wm\WmPackage\TrailRegistry\Nova\Actions\UpdateWalkability;

/**
 * Il contributo e' additivo (oc:8989): uno shard che non accende il Catasto
 * trova il package com'era.
 *
 * Non e' una formalita': l'azione e i renderer si montano su `EcTrack`, che e'
 * una Resource generale, e il `CLAUDE.md` del package ricorda gia' un incidente
 * di questa famiglia — un `canSee()` che chiamava un metodo non ancora presente
 * tolse il menu Nova a tutti gli utenti di un ruolo.
 */
beforeEach(function () {
    runTaxonomyStubs();
    runTrailRegistryStubs();

    config([
        'wm-package.features.trail_registry.enabled' => false,
        'filesystems.disks.s3.region' => 'eu-central-1',
    ]);

    $this->track = EcTrack::factory()->createQuietly(['osmid' => null]);
});

it('non monta l_azione della percorribilità sulle tracce', function () {
    $request = richiestaConRuolo('Administrator');

    $actions = collect((new EcTrackResource(new EcTrack))->actions($request));

    expect($actions->first(fn ($action) => $action instanceof UpdateWalkability))->toBeNull();
});

it('lascia intatte le altre azioni delle tracce', function () {
    $request = richiestaConRuolo('Administrator');

    $actions = collect((new EcTrackResource(new EcTrack))->actions($request));

    expect($actions)->not->toBeEmpty();
});

it('non aggiunge il blocco della percorribilità al dettaglio in API', function () {
    // Il sentiero ha una registrazione: senza, il test passerebbe perché non
    // c'è niente da esporre, non perché il dominio è spento.
    $family = \Wm\WmPackage\Models\TaxonomyTerm::create([
        'identifier' => 'walkability-status',
        'name' => ['it' => 'Percorribilità', 'en' => 'Walkability'],
        'single_choice' => true,
    ]);
    $term = \Wm\WmPackage\Models\TaxonomyTerm::create([
        'identifier' => 'walkability-status-walkable',
        'name' => ['it' => 'Percorribile', 'en' => 'Walkable'],
        'parent_id' => $family->id,
    ]);
    \Wm\WmPackage\TrailRegistry\Models\TrailStatusEvent::create([
        'ec_track_id' => $this->track->id,
        'taxonomy_id' => $family->id,
        'to_term_id' => $term->id,
        'source' => 'import',
        'created_at' => now(),
    ]);

    $properties = $this->track->fresh()->getDetailGeojson()['properties'];

    expect($properties)->not->toHaveKey('walkability');
});

it('costruisce il dettaglio senza sollevare eccezioni', function () {
    expect(fn () => $this->track->fresh()->getDetailGeojson())->not->toThrow(Exception::class);
});
