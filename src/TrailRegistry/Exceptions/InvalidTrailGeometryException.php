<?php

namespace Wm\WmPackage\TrailRegistry\Exceptions;

use RuntimeException;

/**
 * Il file caricato non contiene un tracciato utilizzabile.
 *
 * Il messaggio e' scritto per l'operatore che sta compilando il form, non per
 * il log: distingue i quattro modi in cui un caricamento puo' fallire —
 * formato non riconosciuto, XML non valido, nessuna linea, linea degenere —
 * invece di collassarli in un generico «geometria non valida», che manderebbe
 * fuori strada la diagnosi come e' gia' accaduto sull'import di Sardegna
 * Sentieri.
 */
class InvalidTrailGeometryException extends RuntimeException
{
    public const UNRECOGNIZED_FORMAT = 'unrecognized_format';

    public const INVALID_XML = 'invalid_xml';

    public const INVALID_JSON = 'invalid_json';

    public const NO_USABLE_LINE = 'no_usable_line';

    /**
     * @param  string  $reason  una delle costanti della classe: chi risponde a
     *                          una persona (l'endpoint) distingue il caso da
     *                          qui, non confrontando il testo del messaggio
     */
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function unrecognizedFormat(): self
    {
        return new self('Il file non e\' un GPX ne\' un GeoJSON: caricare un tracciato in uno dei due formati.', self::UNRECOGNIZED_FORMAT);
    }

    public static function invalidXml(): self
    {
        return new self('Il file GPX non e\' XML valido.', self::INVALID_XML);
    }

    public static function invalidJson(): self
    {
        return new self('Il file GeoJSON non e\' JSON valido.', self::INVALID_JSON);
    }

    public static function noUsableLine(): self
    {
        return new self('Il file non contiene nessuna linea con almeno due punti: un tracciato di soli punti di interesse, o un segmento di un solo punto, non descrive un sentiero.', self::NO_USABLE_LINE);
    }
}
