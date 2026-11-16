<?php

use Illuminate\Support\Collection;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Nova\EcTrack as EcTrackResource;
use Wm\WmPackage\TrailRegistry\Enums\TrailStatusEventSource;
use Wm\WmPackage\TrailRegistry\Models\TrailStatusEvent;
use Wm\WmPackage\TrailRegistry\Nova\Actions\UpdateWalkability;
use Wm\WmPackage\TrailRegistry\Services\WalkabilityService;

/**
 * L'azione con cui si aggiorna la percorribilita' dal backoffice (oc:8989):
 * unico punto di scrittura, e l'autorizzazione la verifica da se' nel
 * `canRun()`, perche' un `canRun()` salta quella della Resource.
 */
beforeEach(function () {
    runTaxonomyStubs();
    runTrailRegistryStubs();

    $suffix = uniqid();
    config([
        'wm-package.features.trail_registry.enabled' => true,
        'wm-package.features.trail_registry.walkability_taxonomy' => "walkability-{$suffix}",
    ]);

    $this->family = TaxonomyTerm::create([
        'identifier' => "walkability-{$suffix}",
        'name' => ['it' => 'Percorribilità'],
        'single_choice' => true,
    ]);

    $this->notWalkable = TaxonomyTerm::create([
        'identifier' => "walkability-{$suffix}-not-walkable",
        'name' => ['it' => 'Non percorribile'],
        'parent_id' => $this->family->id,
        'sort_order' => 1,
    ]);

    $this->track = EcTrack::factory()->createQuietly(['osmid' => null]);
});

it('registra lo stato con autore e nota quando la lancia un Editor', function () {
    $request = richiestaConRuolo('Editor');

    (new UpdateWalkability)->handle(
        new ActionFields(collect(['walkability_status' => $this->notWalkable->identifier, 'description' => 'albero caduto al km 3']), collect()),
        new Collection([$this->track]),
    );

    $event = TrailStatusEvent::query()->where('ec_track_id', $this->track->id)->sole();

    expect(app(WalkabilityService::class)->current($this->track)->identifier)->toBe($this->notWalkable->identifier)
        ->and($event->description)->toBe('albero caduto al km 3')
        ->and($event->user_id)->toBe(auth()->id())
        ->and($event->source)->toBe(TrailStatusEventSource::Manual);
});

it('non è eseguibile da chi non può usare il Catasto', function () {
    $request = richiestaConRuolo('Contributor');

    $action = collect((new EcTrackResource(new EcTrack))->actions($request))
        ->first(fn ($action) => $action instanceof UpdateWalkability);

    expect($action)->not->toBeNull()
        ->and($action->authorizedToRun($request, $this->track))->toBeFalse();
});

it('è eseguibile da un Editor', function () {
    $request = richiestaConRuolo('Editor');

    $action = collect((new EcTrackResource(new EcTrack))->actions($request))
        ->first(fn ($action) => $action instanceof UpdateWalkability);

    expect($action->authorizedToRun($request, $this->track))->toBeTrue();
});

it('chiede lo stato come obbligatorio e la nota come facoltativa', function () {
    $request = richiestaConRuolo('Editor');

    $fields = collect((new UpdateWalkability)->fields($request));

    $status = $fields->firstWhere('attribute', 'walkability_status');
    $description = $fields->firstWhere('attribute', 'description');

    expect($status)->not->toBeNull()
        ->and($status->rules)->toContain('required')
        ->and($description)->not->toBeNull()
        ->and($description->rules ?? [])->not->toContain('required');
});
