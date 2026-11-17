<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Filtro Nova di base con il selettore del campo `Choice`: ricerca, icone, chip dei valori scelti
 * (oc:8988).
 *
 * Non sa da dove vengono le opzioni: le fornisce la sottoclasse in `choiceOptions()`, nel formato
 * `{value, label, svg?}`, insieme alla query in `applyChoice()`. Lo estende `TaxonomyTermFilter`
 * per i termini di una tassonomia, e uno shard può estenderlo per opzioni che non vengono da
 * `taxonomy_terms` (in Forestas, le aree catastali).
 *
 * I valori viaggiano come stringhe: la ricerca del selettore fa `toLowerCase()` sul valore e le
 * chip si ricostruiscono confrontando con `===`. Qui si convertono le opzioni; i valori letti
 * dall'URL li converte il componente (`FilterField.vue`), perché Nova li scrive nello store nel
 * browser senza ripassare dal PHP.
 *
 * `key()` resta quella di Nova, il nome della classe: chi migra un filtro esistente su questa
 * base conserva i link salvati.
 */
abstract class ChoiceFilter extends Filter
{
    public $component = 'choice-filter';

    protected bool $multiple = false;

    protected ?string $searchPlaceholder = null;

    /**
     * Le opzioni, nel formato `{value, label, svg?}`.
     *
     * @return array<int, array{value: int|string, label: string, svg?: string}>
     */
    abstract protected function choiceOptions(NovaRequest $request): array;

    /**
     * La query con il valore scelto: una stringa in single, un array di stringhe in multiple, mai
     * vuoto.
     *
     * @param  string|list<string>  $value
     */
    abstract protected function applyChoice(NovaRequest $request, Builder $query, string|array $value): Builder;

    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function searchPlaceholder(string $placeholder): static
    {
        $this->searchPlaceholder = $placeholder;

        return $this;
    }

    /**
     * Un valore vuoto non applica il filtro. Il valore arriva dall'URL, quindi può avere qualsiasi
     * forma: si riduce a quella della modalità prima di passarlo alla sottoclasse.
     */
    final public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        $values = collect(is_array($value) ? $value : [$value])
            ->filter(fn ($item) => is_scalar($item) && (string) $item !== '')
            ->map(fn ($item) => (string) $item)
            ->unique()
            ->values();

        if ($values->isEmpty()) {
            return $query;
        }

        return $this->applyChoice($request, $query, $this->multiple ? $values->all() : $values->first());
    }

    /**
     * @return list<array{value: string, label: string, svg?: string}>
     */
    public function options(NovaRequest $request): array
    {
        return array_values(array_map(
            fn (array $option) => ['value' => (string) $option['value']] + $option,
            $this->choiceOptions($request),
        ));
    }

    /**
     * `[]` in multiple, `''` in single. Nova conta un filtro come attivo confrontando
     * `JSON.stringify` del valore corrente con quello di partenza (`store/resources.js`): con la
     * forma sbagliata, togliere l'ultima chip lascerebbe «1 filtro attivo».
     */
    public function default(): array|string
    {
        return $this->multiple ? [] : '';
    }

    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'multiple' => $this->multiple,
            'searchPlaceholder' => $this->searchPlaceholder,
        ]);
    }
}
