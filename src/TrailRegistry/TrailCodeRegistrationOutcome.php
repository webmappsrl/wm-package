<?php

namespace Wm\WmPackage\TrailRegistry;

use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

/**
 * Esito della registrazione di un codice storico (registerExistingCode()).
 *
 * Un valore, non un booleano: il chiamante (il comando di normalizzazione, o
 * l'import da Sardegna Sentieri) deve poter raccontare cosa e' successo, in
 * un rapporto o in un log, distinguendo cinque casi senza interpretare
 * stringhe sparse. Il flag `sectorMismatch` accompagna l'esito omonimo:
 * resta un campo a se' perche' i chiamanti gia' scritti lo interrogano
 * direttamente per il proprio log.
 *
 * `code` e' la riga scritta nei soli casi in cui una riga viene scritta.
 * Nel caso `alreadyAssigned` nessuna riga nasce — il registro non ospita
 * doppioni — e al suo posto si porta `holder`, la riga di chi quel codice lo
 * porta gia': e' l'unico modo per dire al chiamante A CHI e' assegnato, ora
 * che non c'e' piu' una riga propria da confrontare.
 */
class TrailCodeRegistrationOutcome
{
    private function __construct(
        public readonly string $status,
        public readonly ?TrailRegistryCode $code,
        public readonly bool $sectorMismatch,
        public readonly ?string $fullCode = null,
        public readonly ?int $number = null,
        public readonly ?string $variant = null,
        public readonly ?TrailRegistryCode $holder = null,
    ) {}

    public static function assigned(TrailRegistryCode $code, bool $sectorMismatch, string $fullCode, int $number, string $variant): self
    {
        return new self('assigned', $code, $sectorMismatch, $fullCode, $number, $variant);
    }

    /**
     * Quel codice ce l'ha gia' un altro sentiero: non si scrive nulla. Non e'
     * una contesa fra pari — uno dei due il numero lo porta, l'altro resta
     * senza finche' la fonte non viene corretta.
     *
     * `$holder` e' la riga attiva che lo tiene: puo' mancare solo nella corsa
     * fra due scritture simultanee, se chi lo teneva lo libera subito dopo
     * aver fatto fallire questa.
     */
    public static function alreadyAssigned(?TrailRegistryCode $holder, bool $sectorMismatch, string $fullCode, int $number, string $variant): self
    {
        return new self('alreadyAssigned', null, $sectorMismatch, $fullCode, $number, $variant, $holder);
    }

    /**
     * Il settore scritto nel codice non e' quello in cui la traccia ricade:
     * non si registra nulla. Un codice che contraddice la geometria sarebbe
     * un dato falso nel registro, e nessuno saprebbe piu' quale dei due
     * elementi credere — il registro tiene solo codici su cui non pende
     * alcun dubbio, il caso va nella lista di lavoro.
     */
    public static function sectorMismatch(string $fullCode, int $number, string $variant): self
    {
        return new self('sectorMismatch', null, true, $fullCode, $number, $variant);
    }

    public static function alreadyRegistered(bool $sectorMismatch = false, ?string $fullCode = null, ?int $number = null, ?string $variant = null): self
    {
        return new self('alreadyRegistered', null, $sectorMismatch, $fullCode, $number, $variant);
    }

    public static function unparsableRef(): self
    {
        return new self('unparsableRef', null, false);
    }

    public static function noSector(): self
    {
        return new self('noSector', null, false);
    }
}
