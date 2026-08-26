<?php

namespace Wm\WmPackage\Enums;

/**
 * Scala della rete escursionistica a cui un percorso appartiene.
 *
 * I valori replicano il vocabolario del tag OpenStreetMap `network=*`
 * per le reti pedonali (`lwn`/`rwn`/`nwn`/`iwn` = local/regional/national/
 * international walking network). Il nome della classe espone
 * esplicitamente la provenienza OSM per non essere confuso con la rete
 * dati in contesto mobile app; la semantica di prodotto ("portata" del
 * percorso) è veicolata dalle label tradotte restituite da label().
 *
 * Il valore è scelto manualmente dall'utente: nessuna lettura automatica
 * del tag OSM è implementata.
 */
enum OsmWalkingNetwork: string
{
    case LWN = 'lwn';
    case RWN = 'rwn';
    case NWN = 'nwn';
    case IWN = 'iwn';

    /**
     * Etichetta tradotta in una lingua specifica, indipendente dalla locale
     * attiva: serve al consumer per comporre la mappa delle traduzioni da
     * esporre nel config, senza dover duplicare le etichette lato client.
     */
    public function labelIn(string $locale): string
    {
        return match ($this) {
            self::LWN => __('Local walking network', [], $locale),
            self::RWN => __('Regional walking network', [], $locale),
            self::NWN => __('National walking network', [], $locale),
            self::IWN => __('International walking network', [], $locale),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::LWN => __('Local walking network'),
            self::RWN => __('Regional walking network'),
            self::NWN => __('National walking network'),
            self::IWN => __('International walking network'),
        };
    }

    /**
     * @return array<string, string> value => label, pronto per Select::options()
     */
    public static function toArray(): array
    {
        return array_reduce(self::cases(), function (array $carry, OsmWalkingNetwork $network) {
            $carry[$network->value] = $network->label();

            return $carry;
        }, []);
    }
}
