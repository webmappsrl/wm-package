<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Cards;

use Laravel\Nova\Card;

/**
 * Un riquadro di testo in cima a un elenco.
 *
 * Nova non offre un modo nativo per spiegare una schermata: la card `Help`
 * esiste ma ha il contenuto scritto dentro il componente. Questa prende il
 * testo da chi la crea, e il suo componente e' registrato con una render
 * function in resources/js/domains/trail_registry.js — nessun bundle nuovo.
 *
 * Compare solo nell'elenco: `$onlyOnDetail` resta false, e Nova mostra sulla
 * scheda del singolo record soltanto le card che lo dichiarano.
 */
class TrailRegistryNoticeCard extends Card
{
    public $component = 'trail-registry-notice-card';

    public $width = 'full';

    public function __construct(private string $body)
    {
        parent::__construct();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'body' => $this->body,
        ]);
    }
}
