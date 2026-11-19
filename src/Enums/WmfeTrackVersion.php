<?php

declare(strict_types=1);

namespace Wm\WmPackage\Enums;

use InvalidArgumentException;

/**
 * Le versioni del formato del JSON del dettaglio di una traccia su `wmfe` (oc:8997).
 *
 * `Legacy` è il file senza versione, `<shard>/tracks/{id}.json`: l'indirizzo compilato dentro le
 * app già pubblicate sugli store, che non si sposta e non cambia forma. `V1` è il primo contratto
 * numerato, `<shard>/tracks/v1/{id}.json`.
 *
 * **I casi stanno in ordine cronologico, e l'ultimo è la forma canonica** (`current()`): le
 * properties si costruiscono per intero in quella forma, e le versioni precedenti si ottengono
 * applicando a ritroso una trasformazione per versione. Una versione nuova si aggiunge quindi in
 * fondo, mai in mezzo.
 *
 * Quali versioni lo shard scrive davvero lo dice `active()`, che legge
 * `wm-package.wmfe_track_versions` (variabile `WMFE_TRACK_VERSIONS`). A dichiarazione assente
 * resta attiva la sola `Legacy`: uno shard che bumpa il package senza sapere niente di questo
 * meccanismo continua a scrivere un file solo, come ha sempre fatto.
 */
enum WmfeTrackVersion: string
{
    case Legacy = 'legacy';
    case V1 = 'v1';

    /**
     * Il pezzo di path che distingue questa versione, fra `tracks/` e il nome del file.
     */
    public function pathPrefix(): string
    {
        return $this === self::Legacy ? '' : $this->value.'/';
    }

    /**
     * La forma canonica: l'ultima versione conosciuta.
     */
    public static function current(): self
    {
        $cases = self::cases();

        return $cases[count($cases) - 1];
    }

    /**
     * Le versioni che questo shard scrive, nell'ordine in cui le ha dichiarate.
     *
     * Un nome sconosciuto fa fallire la scrittura invece di far scrivere file sotto un prefisso
     * che nessuno legge: è il guasto silenzioso che un refuso nel `.env` produrrebbe.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        $declaration = config('wm-package.wmfe_track_versions');

        $names = array_filter(array_map(
            'trim',
            explode(',', is_string($declaration) ? $declaration : '')
        ), fn (string $name) => $name !== '');

        $versions = [];
        foreach (array_unique($names) as $name) {
            $version = self::tryFrom($name);

            if ($version === null) {
                throw new InvalidArgumentException(sprintf(
                    'Versione del JSON della traccia sconosciuta: "%s". Valide: %s.',
                    $name,
                    implode(', ', array_column(self::cases(), 'value'))
                ));
            }

            $versions[] = $version;
        }

        return $versions === [] ? [self::Legacy] : $versions;
    }
}
