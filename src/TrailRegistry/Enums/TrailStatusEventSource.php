<?php

namespace Wm\WmPackage\TrailRegistry\Enums;

/**
 * Da dove viene un cambio di stato (oc:8989).
 *
 * Serve perche' `user_id` e' vuoto in tutti i casi tranne l'inserimento a
 * mano: una cronologia scritta in gran parte da un processo orario sarebbe
 * altrimenti una sequenza di righe senza nome. Dove la storia dei codici
 * scrive un trattino per l'utente assente, qui si scrive la provenienza.
 */
enum TrailStatusEventSource: string
{
    /** Una persona, dal backoffice. E' l'unico caso con `user_id` valorizzato. */
    case Manual = 'manual';

    /** Un import automatico: il valore lo dichiara la piattaforma di origine. */
    case Import = 'import';

    /** L'allineamento iniziale, che registra lo stato di partenza di ogni sentiero. */
    case Migration = 'migration';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Manual entry'),
            self::Import => __('Import'),
            self::Migration => __('Initial alignment'),
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
