<?php

namespace Wm\WmPackage\Http\Requests\TrailRegistry\Concerns;

use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

/**
 * Le regole dei campi del modulo, costruite allo stesso modo per la creazione
 * e per le modifiche.
 *
 * Stanno insieme perche' sono la stessa cosa: il contratto dei campi non
 * cambia a seconda di quando arrivano. Cambia solo cio' che la chiamata
 * aggiunge di suo — il riferimento e il tracciato alla creazione, il tracciato
 * facoltativo nella modifica — e quello resta nel `rules()` di ciascuna.
 */
trait BuildsApplicationFieldRules
{
    /**
     * Le regole dei campi dello schema, con gli intervalli di plausibilita' e
     * con l'obbligatorieta' resa condizionata alla presenza del campo.
     *
     * `sometimes` davanti a `required` significa «se lo mandi deve valere
     * qualcosa, se non lo mandi non e' un errore»: la domanda nasce con il
     * minimo e si riempie in piu' riprese, quindi l'obbligatorieta' dichiarata
     * dallo schema e' il metro della conferma, non di ogni consegna.
     *
     * Solo le chiavi di primo livello: le regole annidate
     * (`managing_subjects.*.name`) descrivono com'e' fatto un elemento di una
     * lista e valgono per intero su ogni elemento che arriva. Una lista
     * consegnata a meta' resta un errore.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function applicationFieldRules(): array
    {
        $rules = app(ApplicationSchema::class)->rules();

        foreach (ApplicationSchema::PLAUSIBLE_RANGES as $field => [$min, $max]) {
            if (isset($rules[$field])) {
                $rules[$field][] = "between:{$min},{$max}";
            }
        }

        foreach ($rules as $field => $fieldRules) {
            if (! str_contains((string) $field, '.') && in_array('required', $fieldRules, true)) {
                array_unshift($rules[$field], 'sometimes');
            }
        }

        return $rules;
    }
}
