<?php

namespace Wm\WmPackage\TrailRegistry\Services;

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Enums\TrailStatusEventSource;
use Wm\WmPackage\TrailRegistry\Models\TrailStatusEvent;

/**
 * La percorribilita' di un sentiero (oc:8989).
 *
 * Il lavoro vero lo fa `TrailStatusService`, che sa scrivere lo stato di una
 * famiglia qualsiasi — lock, rilettura, evento, legame. Qui si fissa quale
 * famiglia, letta dalla configurazione dello shard, cosi' chi scrive la
 * percorribilita' non deve ricordarsene il nome. Gli altri stati del sentiero —
 * oggi la validazione — passano dal service generico con il proprio identifier.
 *
 * Delega e non eredita: le due firme sono diverse di proposito (qui la famiglia
 * non si passa), e in PHP una sottoclasse non puo' restringere cosi' un metodo.
 */
class WalkabilityService
{
    public function __construct(private TrailStatusService $statuses) {}

    /**
     * @param  string|null  $expectedFromIdentifier  Lo stato da cui chi scrive crede di partire:
     *                                               se non e' piu' quello la scrittura si rifiuta.
     */
    public function set(
        EcTrack $track,
        string $toIdentifier,
        ?string $description = null,
        ?int $userId = null,
        ?TrailStatusEventSource $source = null,
        ?string $expectedFromIdentifier = null,
    ): ?TrailStatusEvent {
        return $this->statuses->set(
            $track,
            self::taxonomyIdentifier(),
            $toIdentifier,
            $description,
            $userId,
            $source ?? TrailStatusEventSource::Manual,
            $expectedFromIdentifier,
        );
    }

    /** Lo stato di percorribilita' di un sentiero, se ne ha uno. */
    public function current(EcTrack $track): ?TaxonomyTerm
    {
        return $this->statuses->current($track, self::taxonomyIdentifier());
    }

    public static function taxonomyIdentifier(): string
    {
        /** @var string $identifier */
        $identifier = config('wm-package.features.trail_registry.walkability_taxonomy', 'walkability-status');

        return $identifier;
    }
}
