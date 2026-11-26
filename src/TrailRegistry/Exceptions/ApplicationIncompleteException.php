<?php

namespace Wm\WmPackage\TrailRegistry\Exceptions;

use RuntimeException;

/**
 * Il fascicolo non e' completo: la compilazione non si puo' chiudere.
 *
 * Porta i due elenchi di `TrailApplication::missingForConfirmation()` come
 * dato e non come testo, perche' chi la cattura — l'endpoint di conferma —
 * deve dire al chiamante cosa manca campo per campo e documento per
 * documento, e non deve ricavarlo facendo il parsing del messaggio. Il
 * messaggio e' per i log e per chi debugga, non per chi riceve la risposta.
 */
class ApplicationIncompleteException extends RuntimeException
{
    /**
     * @param  list<string>  $fields  identifier dei campi di `api_data` mancanti
     * @param  list<string>  $attachments  nomi delle collection di allegati mancanti
     * @param  array<string, list<string>>  $invalid  campi presenti ma con un valore non ammesso
     */
    public function __construct(
        private readonly array $fields,
        private readonly array $attachments,
        private readonly array $invalid = [],
    ) {
        parent::__construct(sprintf(
            "Il fascicolo non si puo' chiudere: campi mancanti [%s], allegati mancanti [%s], valori non validi [%s].",
            implode(', ', $fields),
            implode(', ', $attachments),
            implode(', ', array_keys($invalid)),
        ));
    }

    /**
     * @param  array{fields: list<string>, attachments: list<string>}  $missing  l'esito di `missingForConfirmation()`
     */
    public static function forMissing(array $missing, array $invalid = []): self
    {
        return new self($missing['fields'], $missing['attachments'], $invalid);
    }

    /**
     * @return array{fields: list<string>, attachments: list<string>}
     */
    public function missing(): array
    {
        return ['fields' => $this->fields, 'attachments' => $this->attachments];
    }

    /**
     * I campi che ci sono ma non vanno bene, con il motivo.
     *
     * E' l'altra meta' del controllo, nata con la compilazione in piu'
     * riprese: finche' l'unica porta era la consegna intera, un valore
     * impossibile non poteva entrare, perche' la validazione girava li'. Ora
     * che la domanda si riempie un pezzo per volta, la conferma deve guardare
     * anche cosa c'e' dentro, non solo che ci sia.
     *
     * @return array<string, list<string>>
     */
    public function invalid(): array
    {
        return $this->invalid;
    }
}
