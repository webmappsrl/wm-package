<?php

namespace Wm\WmPackage\TrailRegistry\Exceptions;

use RuntimeException;

/**
 * La famiglia di difficolta' di un'attivita' non esiste a database: e' un problema
 * di configurazione dell'installazione, non un valore sbagliato di chi compila.
 */
class MissingDifficultyFamilyException extends RuntimeException
{
    public function __construct(string $family, string $activity)
    {
        parent::__construct(
            "La famiglia di difficolta' \"{$family}\" (attivita' \"{$activity}\") non esiste in taxonomy_terms: le scale di difficolta' non sono state configurate su questa installazione."
        );
    }
}
