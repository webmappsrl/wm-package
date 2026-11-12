<?php

namespace Wm\WmPackage\TrailRegistry\Schema;

/**
 * Un campo del modulo di domanda di accatastamento.
 *
 * E' un oggetto tipizzato e non un array: una chiave non dichiarata qui non
 * si puo' aggiungere in silenzio, e la dichiarazione di una nuova si vede in
 * review.
 */
final class ApplicationField
{
    /**
     * @param  string  $name  l'identifier del campo nel payload
     * @param  string  $type  il tipo con cui il campo si valida
     * @param  string  $label  l'etichetta mostrata
     * @param  array<int, array<string, mixed>>|null  $values  i valori di una select, se l'elenco e' fissato
     * @param  bool  $required  vale solo per l'obbligatorieta' incondizionata: un campo che dipende da un
     *                          altro campo ha `false` e la condizione dentro `$rules`
     * @param  array<int, mixed>  $rules  le regole di validazione Laravel del campo
     * @param  string|null  $displayType  il tipo con cui il campo si mostra quando differisce da `$type`
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $label,
        public readonly ?array $values,
        public readonly bool $required,
        public readonly array $rules,
        public readonly ?string $displayType,
    ) {}

    /**
     * La forma che `PropertiesPanel::jsonForm()` consuma: `values` compare
     * solo per le select che ne hanno, e `type` e' quello di visualizzazione
     * quando c'e'.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $field = [
            'name' => $this->name,
            'type' => $this->displayType ?? $this->type,
            'label' => $this->label,
        ];

        if ($this->values !== null) {
            $field['values'] = $this->values;
        }

        return $field;
    }
}
