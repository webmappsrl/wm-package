<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Cards;

/**
 * Quanti sentieri ci sono per ogni stato di percorribilita' (oc:8989): il
 * semaforo del sito visto dal lato di chi lo deve riempire.
 *
 * E' `TaxonomyDistributionCard` con la famiglia e l'esclusione gia' decise.
 *
 * **Gli sconosciuti restano fuori dall'elenco** e si leggono come numero
 * accanto. Finche' il dato non e' a regime sono la maggioranza — alla partenza
 * quattro sentieri su cinque — e dentro il conteggio schiaccerebbero la
 * composizione di quelli che un dato ce l'hanno, rendendo inutile la card
 * proprio nel periodo in cui serve guardarla. E' anche la regola che il
 * wireframe del sito ha gia' fissato per il semaforo, dove il livello
 * sconosciuto non entra nei conteggi.
 */
class WalkabilityDistributionCard extends TaxonomyDistributionCard
{
    public function __construct()
    {
        $identifier = self::taxonomyIdentifier();

        parent::__construct(
            $identifier,
            __('Trail walkability'),
            "{$identifier}-unknown",
            __(':count trails with no known walkability, left out of the total of :total.'),
        );
    }

    private static function taxonomyIdentifier(): string
    {
        /** @var string $identifier */
        $identifier = config('wm-package.features.trail_registry.walkability_taxonomy', 'walkability-status');

        return $identifier;
    }
}
