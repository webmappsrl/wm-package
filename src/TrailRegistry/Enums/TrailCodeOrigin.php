<?php

namespace Wm\WmPackage\TrailRegistry\Enums;

/**
 * Come il codice di una riga del registro e' nato.
 *
 * Non e' un dettaglio storico: e' cio' che permette a Forestas di fidarsi o
 * di verificare. Nessuno dei tre valori nomina `ref`: e' come quella
 * proprieta' si chiama su forestas (config
 * `features.trail_registry.legacy_code_property`), non altrove.
 */
enum TrailCodeOrigin: string
{
    /** Letto dalla proprieta' che deve contenerlo. */
    case CampoDedicato = 'campo_dedicato';

    /** Estratto dalle parentesi finali del nome. */
    case Nome = 'nome';

    /** Proposto dalla piattaforma: il primo numero libero. */
    case Assegnato = 'assegnato';
}
