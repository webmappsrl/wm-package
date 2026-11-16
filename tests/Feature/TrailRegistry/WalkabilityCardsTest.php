<?php

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Inconsistencies\InconsistencyTypeDefinition;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Nova\Cards\TrailRegistryInconsistenciesCard;
use Wm\WmPackage\TrailRegistry\Nova\Cards\WalkabilityDistributionCard;

/**
 * Le due card del Catasto sulla dashboard (oc:8989): la distribuzione dei
 * sentieri per stato e i numeri delle incongruenze.
 */
class CardsFakeInconsistencyType implements InconsistencyTypeDefinition
{
    public function label(): string
    {
        return 'Validato senza percorribilità';
    }

    public function description(): string
    {
        return 'Nessuno ha detto se si cammina.';
    }
}

function walkabilityCardsTerm(string $identifier, string $name, ?int $parentId = null, int $order = 0): TaxonomyTerm
{
    return TaxonomyTerm::create([
        'identifier' => $identifier,
        'name' => ['it' => $name, 'en' => $name],
        'parent_id' => $parentId,
        'sort_order' => $order,
    ]);
}

beforeEach(function () {
    runTaxonomyStubs();
    runTrailRegistryStubs();

    $suffix = uniqid();
    config(['wm-package.features.trail_registry.walkability_taxonomy' => "walkability-{$suffix}"]);

    $this->family = walkabilityCardsTerm("walkability-{$suffix}", 'Percorribilità');
    $this->unknown = walkabilityCardsTerm("walkability-{$suffix}-unknown", 'Sconosciuta', $this->family->id, 0);
    $this->walkable = walkabilityCardsTerm("walkability-{$suffix}-walkable", 'Percorribile', $this->family->id, 3);
    $this->notWalkable = walkabilityCardsTerm("walkability-{$suffix}-not-walkable", 'Non percorribile', $this->family->id, 1);
});

/** Attacca un termine a `$count` sentieri nuovi. */
function walkabilityCardsAttach(TaxonomyTerm $term, int $count): void
{
    foreach (range(1, $count) as $ignored) {
        $track = EcTrack::factory()->createQuietly(['osmid' => null]);
        $track->taxonomyTerms()->attach($term->id);
    }
}

it('conta i sentieri per stato, lasciando gli sconosciuti fuori dal grafico', function () {
    walkabilityCardsAttach($this->walkable, 4);
    walkabilityCardsAttach($this->notWalkable, 1);
    walkabilityCardsAttach($this->unknown, 10);

    $card = (new WalkabilityDistributionCard)->jsonSerialize();

    expect(array_sum(array_column($card['slices'], 'count')))->toBe(5)
        ->and($card['excludedCount'])->toBe(10)
        ->and(array_column($card['slices'], 'identifier'))->not->toContain($this->unknown->identifier);
});

it('legge gli stati dal database, non da un elenco nel codice', function () {
    $extra = walkabilityCardsTerm($this->family->identifier.'-closed-for-works', 'Chiuso per lavori', $this->family->id, 2);
    walkabilityCardsAttach($extra, 2);

    $card = (new WalkabilityDistributionCard)->jsonSerialize();

    expect(array_column($card['slices'], 'identifier'))->toContain($extra->identifier);
});

it('non esplode quando nessun sentiero ha uno stato', function () {
    $card = (new WalkabilityDistributionCard)->jsonSerialize();

    expect(array_sum(array_column($card['slices'], 'count')))->toBe(0)
        ->and($card['excludedCount'])->toBe(0);
});

it('conta le incongruenze per tipo', function () {
    config(['wm-package.features.trail_registry.inconsistency_types' => [
        'missing_walkability' => CardsFakeInconsistencyType::class,
    ]]);

    $track = EcTrack::factory()->createQuietly(['osmid' => null]);
    // Le incongruenze vivono nella tabella delle anomalie, distinte dal
    // `source`: e' il campo che esiste apposta per la provenienza, e che il
    // registro catastale usa allo stesso modo.
    TrailRegistryAnomaly::create([
        'ec_track_id' => $track->id,
        'type' => 'missing_walkability',
        'source' => 'inconsistency',
        'created_at' => now(),
    ]);

    $card = (new TrailRegistryInconsistenciesCard)->jsonSerialize();

    expect($card['items'])->toHaveCount(1)
        ->and($card['items'][0]['label'])->toBe('Validato senza percorribilità')
        ->and($card['items'][0]['count'])->toBe(1);
});

it('non mostra niente quando lo shard non dichiara incongruenze', function () {
    config(['wm-package.features.trail_registry.inconsistency_types' => []]);

    $card = (new TrailRegistryInconsistenciesCard)->jsonSerialize();

    expect($card['items'])->toBe([]);
});
