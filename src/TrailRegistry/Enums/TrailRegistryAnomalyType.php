<?php

namespace Wm\WmPackage\TrailRegistry\Enums;

/**
 * I casi in cui il codice di un sentiero non torna.
 *
 * Nessuno di questi si corregge qui: il dato lo possiede la piattaforma di
 * origine, il gestore sistema la scheda alla fonte e l'import successivo si
 * porta via la riga. Per questo l'elenco delle anomalie e' una lista di
 * lavoro e non uno storico — vedi TrailRegistryNormalizeCommand.
 *
 * Un codice scritto nel nome invece che nella proprieta' dedicata NON e'
 * un'anomalia: si legge, si registra, e il registro annota la provenienza
 * (`TrailCodeOrigin::Nome`). Cio' che si puo' risolvere si risolve, e la
 * lista di lavoro resta corta abbastanza da essere letta davvero.
 */
enum TrailRegistryAnomalyType: string
{
    /** La posizione e' di un altro sentiero: questo resta senza numero. */
    case CodiceGiaAssegnato = 'codice_gia_assegnato';

    /** Il settore scritto nel codice non e' quello in cui la traccia ricade. */
    case SettoreDiscordante = 'settore_discordante';

    /** Due tracce con la stessa identica geometria. */
    case GeometriaDuplicata = 'geometria_duplicata';

    /** Dalla proprieta' non si ricava una coda valida. */
    case CodiceIlleggibile = 'codice_illeggibile';

    /** Nessun settore contiene la traccia. */
    case FuoriDaOgniSettore = 'fuori_da_ogni_settore';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
