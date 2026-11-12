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
     */
    public function __construct(
        private readonly array $fields,
        private readonly array $attachments,
    ) {
        parent::__construct(sprintf(
            "Il fascicolo non e' completo: campi mancanti [%s], allegati mancanti [%s].",
            implode(', ', $fields),
            implode(', ', $attachments),
        ));
    }

    /**
     * @param  array{fields: list<string>, attachments: list<string>}  $missing  l'esito di `missingForConfirmation()`
     */
    public static function forMissing(array $missing): self
    {
        return new self($missing['fields'], $missing['attachments']);
    }

    /**
     * @return array{fields: list<string>, attachments: list<string>}
     */
    public function missing(): array
    {
        return ['fields' => $this->fields, 'attachments' => $this->attachments];
    }
}
