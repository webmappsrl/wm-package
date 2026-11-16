<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Models\TrailStatusEvent;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * La tabella degli eventi di stato di un sentiero: percorribilita' oggi, altre
 * classificazioni a scelta singola domani (oc:8989).
 *
 * I termini si creano qui con un identifier unico, come vuole la trappola dei
 * test del package: un identifier fisso gia' presente fa scattare il controllo
 * dei doppioni di TaxonomyObserver.
 */
beforeEach(function () {
    runTaxonomyStubs();
    runTrailRegistryStubs();

    $suffix = uniqid();

    $this->family = TaxonomyTerm::create([
        'identifier' => "walkability-status-{$suffix}",
        'name' => ['it' => 'Percorribilità'],
        'single_choice' => true,
    ]);

    $this->walkable = TaxonomyTerm::create([
        'identifier' => "walkability-status-walkable-{$suffix}",
        'name' => ['it' => 'Percorribile'],
        'parent_id' => $this->family->id,
    ]);

    // `createQuietly`: gli observer della traccia escono verso lo storage, come
    // fanno gli altri test del dominio. `osmid` nullo perche' la factory lo
    // valorizza a caso nel 70% dei casi.
    $this->track = EcTrack::factory()->createQuietly(['osmid' => null]);
    $this->user = User::factory()->create();
});

it('scrive un evento con tutte le sue colonne', function () {
    $event = TrailRegistryClasses::statusEvent()::create([
        'ec_track_id' => $this->track->id,
        'taxonomy_id' => $this->family->id,
        'from_term_id' => null,
        'to_term_id' => $this->walkable->id,
        'description' => 'albero caduto al km 3',
        'user_id' => $this->user->id,
        'source' => 'manual',
        'created_at' => now(),
    ]);

    expect($event->fresh()->toTerm->identifier)->toBe($this->walkable->identifier)
        ->and($event->fresh()->taxonomy->id)->toBe($this->family->id)
        ->and($event->fresh()->ecTrack->id)->toBe($this->track->id)
        ->and($event->fresh()->user->id)->toBe($this->user->id);
});

it('non lascia cancellare un termine che ha storia', function () {
    TrailStatusEvent::create([
        'ec_track_id' => $this->track->id,
        'taxonomy_id' => $this->family->id,
        'to_term_id' => $this->walkable->id,
        'source' => 'manual',
        'created_at' => now(),
    ]);

    expect(fn () => DB::table('taxonomy_terms')->where('id', $this->walkable->id)->delete())
        ->toThrow(QueryException::class);
});

it('conserva l_evento quando l_utente viene cancellato', function () {
    $event = TrailStatusEvent::create([
        'ec_track_id' => $this->track->id,
        'taxonomy_id' => $this->family->id,
        'to_term_id' => $this->walkable->id,
        'user_id' => $this->user->id,
        'source' => 'manual',
        'created_at' => now(),
    ]);

    $this->user->delete();

    expect($event->fresh())->not->toBeNull()
        ->and($event->fresh()->user_id)->toBeNull()
        ->and($event->fresh()->to_term_id)->toBe($this->walkable->id);
});
