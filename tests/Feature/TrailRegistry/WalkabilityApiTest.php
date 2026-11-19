<?php

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailStatusEventSource;
use Wm\WmPackage\TrailRegistry\Services\WalkabilityService;

/**
 * Come la percorribilita' esce nel dettaglio pubblico della traccia (oc:8989):
 * lo stato dove escono tutte le classificazioni, e i suoi dati in un blocco
 * accanto.
 */
function walkabilityApiTerm(string $identifier, string $name, ?int $parentId = null, int $order = 0): TaxonomyTerm
{
    return TaxonomyTerm::create([
        'identifier' => $identifier,
        // Lo stesso nome in `it` e in `en`: nella suite il locale non e'
        // fissato, e un nome solo italiano farebbe asserire sulla stringa vuota.
        'name' => ['it' => $name, 'en' => $name],
        'parent_id' => $parentId,
        'sort_order' => $order,
    ]);
}

beforeEach(function () {
    // Il disco di wmfe e' finto: senza, un percorso che arriva a una scrittura prova a parlare
    // con S3 vero. Nessun test deve scrivere fuori (regola in cima al CLAUDE.md del package).
    Storage::fake('wmfe');

    runTaxonomyStubs();
    runTrailRegistryStubs();

    config([
        'filesystems.disks.s3.region' => 'eu-central-1',
        // Il blocco esce solo a dominio acceso: e' il requisito dell'additività.
        'wm-package.features.trail_registry.enabled' => true,
    ]);

    $suffix = uniqid();
    config(['wm-package.features.trail_registry.walkability_taxonomy' => "walkability-{$suffix}"]);

    $this->family = walkabilityApiTerm("walkability-{$suffix}", 'Percorribilità');
    $this->family->single_choice = true;
    $this->family->save();

    $this->notWalkable = walkabilityApiTerm("walkability-{$suffix}-not-walkable", 'Non percorribile', $this->family->id, 1);

    $this->appModel = App::factory()->createQuietly();
    $this->appModel->syncTaxonomiesForModel('EcTrack', [$this->family->id]);

    $this->track = EcTrack::factory()->createQuietly(['osmid' => null, 'app_id' => $this->appModel->id]);
    $this->user = User::factory()->create();
});

it('espone lo stato fra le tassonomie e i suoi dati nel blocco accanto', function () {
    app(WalkabilityService::class)->set(
        $this->track,
        $this->notWalkable->identifier,
        'albero caduto al km 3',
        $this->user->id,
        TrailStatusEventSource::Manual,
    );

    $properties = $this->track->fresh()->getDetailGeojson()['properties'];

    expect($properties['taxonomies'])->toHaveKey($this->family->identifier)
        ->and($properties['walkability']['user'])->toBe($this->user->id)
        ->and($properties['walkability']['description'])->toBe('albero caduto al km 3')
        ->and($properties['walkability']['last_update'])->not->toBeEmpty();
});

it('non espone il blocco su un sentiero senza registrazioni', function () {
    $properties = $this->track->fresh()->getDetailGeojson()['properties'];

    expect($properties)->not->toHaveKey('walkability');
});

it('non espone la cronologia completa', function () {
    app(WalkabilityService::class)->set($this->track, $this->notWalkable->identifier, null, null, TrailStatusEventSource::Import);

    $properties = $this->track->fresh()->getDetailGeojson()['properties'];

    expect($properties['walkability'])->not->toHaveKey('history')
        ->and(array_keys($properties['walkability']))->toEqualCanonicalizing(['last_update', 'user', 'description']);
});

it('espone l_autore come id, non come nome', function () {
    app(WalkabilityService::class)->set($this->track, $this->notWalkable->identifier, null, $this->user->id, TrailStatusEventSource::Manual);

    $properties = $this->track->fresh()->getDetailGeojson()['properties'];

    expect($properties['walkability']['user'])->toBe($this->user->id)
        ->and(json_encode($properties['walkability']))->not->toContain($this->user->name);
});

it('lascia l_autore vuoto quando lo stato viene da un processo automatico', function () {
    app(WalkabilityService::class)->set($this->track, $this->notWalkable->identifier, null, null, TrailStatusEventSource::Import);

    $properties = $this->track->fresh()->getDetailGeojson()['properties'];

    expect($properties['walkability']['user'])->toBeNull();
});
