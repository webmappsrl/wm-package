<?php

namespace Wm\WmPackage\TrailRegistry;

/**
 * Lettura di un codice sentiero gia' scritto.
 *
 * Funzione pura: nessun database, nessuno stato. Dal testo si estraggono solo
 * la coda numerica e l'eventuale variante — il prefisso (regione, provincia,
 * area, settore) si ricava SEMPRE dalla geometria, mai da come il codice e'
 * scritto: i ref storici hanno cinque forme diverse e due numeri uguali
 * possono appartenere a settori diversi (il caso `D 700` / `T-700`).
 *
 * La coda regolare e' di tre cifre: la prima e' il settore, le altre due il
 * numero. Una coda di quattro cifre sarebbe un sottosentiero del modello
 * nazionale CAI (3111, 3112), fuori scope: si rifiuta.
 */
class TrailCodeParser
{
    /**
     * @return array{number: int, variant: string}|null
     */
    public static function parseTail(string $raw): ?array
    {
        $matches = self::match($raw);

        if ($matches === null) {
            return null;
        }

        return [
            'number' => (int) substr($matches['digits'], -2),
            'variant' => $matches['variant'],
        ];
    }

    /**
     * La cifra del settore come e' scritta nel codice. Serve solo alla prova a
     * vuoto, per confrontarla con il settore dedotto dalla geometria e misurare
     * la qualita' del dato: non e' una fonte da cui comporre il codice.
     */
    public static function sectorDigitFrom(string $raw): ?string
    {
        $matches = self::match($raw);

        return $matches === null ? null : substr($matches['digits'], 0, 1);
    }

    /**
     * @return array{digits: string, variant: string}|null
     */
    private static function match(string $raw): ?array
    {
        // Si normalizza il separatore e si guarda solo la fine della stringa:
        // tutte le forme finiscono con tre cifre piu' l'eventuale lettera.
        $normalized = strtoupper(preg_replace('/[\s\-_]+/', '', trim($raw)) ?? '');

        if (! preg_match('/(?<digits>\d{3})(?<variant>[A-Z])?$/', $normalized, $m)) {
            return null;
        }

        // Quattro o piu' cifre consecutive in coda: sottosentiero, si rifiuta.
        if (preg_match('/\d{4}[A-Z]?$/', $normalized)) {
            return null;
        }

        return [
            'digits' => $m['digits'],
            'variant' => $m['variant'] ?? '0',
        ];
    }
}
