<?php

namespace Wm\WmPackage\Nova\Fields\Choice;

use Closure;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\SupportsDependentFields;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Il campo con cui si sceglie: una opzione o molte, con la sua icona, e solo quando ha senso
 * (oc:8910).
 *
 * Si chiama così perché la cardinalità non è una sua caratteristica ma una configurazione:
 * `->multiple(true|false)`. Nova e Outl1ne hanno due classi distinte, una per la scelta singola
 * e una per la multipla, e chi costruisce il campo deve saperlo in anticipo; qui il dato lo
 * decide — per le tassonomie è `single_choice` della loro riga — e se domani cambia non cambia
 * una riga di codice. «Scelta» non dice né una né molte, ed è il punto.
 *
 * Cosa aggiunge agli altri due: l'icona accanto all'opzione, che nessuno dei due mostra, e
 * `visibleWhen()`, che dichiara in una riga la dipendenza da un altro campo — `dependsOn` ce
 * l'hanno tutti, ma come callback da scrivere, e senza il rifiuto al salvataggio.
 *
 * Non sa niente di nessun modello: riceve opzioni `{value, label, svg?}` e restituisce i valori
 * scelti. Chi la usa decide da dove vengono le opzioni e dove finiscono i valori — una
 * tassonomia di `taxonomy_terms`, una tassonomia con la sua tabella, o altro ancora.
 *
 * Il componente che la disegna sta in questa stessa cartella, compilato in `dist/` e
 * registrato da `FieldServiceProvider`. Lo usa anche `IconSelect`, che estende questa classe per
 * scegliere il nome di un'icona e non dichiara un componente suo.
 */
class Choice extends Field
{
    // Senza questo trait `dependsOn()` non esiste sul campo: Field non lo porta da sé, e la
    // visibilità condizionata resterebbe lettera morta.
    use SupportsDependentFields;

    public $component = 'choice';

    /**
     * Le opzioni. `value` è una stringa: la ricerca del componente lavora su stringhe.
     *
     * @param  array<int, array{value: string, label: string, svg?: string}>  $options
     * @return $this
     */
    public function options(array $options)
    {
        return $this->withMeta(['options' => array_values($options)]);
    }

    /**
     * @return $this
     */
    public function multiple(bool $multiple = true)
    {
        return $this->withMeta(['multiple' => $multiple]);
    }

    /**
     * @return $this
     */
    public function searchPlaceholder(string $placeholder)
    {
        return $this->withMeta(['searchPlaceholder' => $placeholder]);
    }

    /**
     * Mostra questo campo solo quando un altro campo del form ha uno dei valori dati.
     *
     * Fa due cose, e servono entrambe: nasconde il campo nel form, e **rifiuta il salvataggio**
     * quando la condizione non vale. Nascondere non è validare — un POST diretto scriverebbe il
     * valore lo stesso.
     *
     * La condizione è vera anche quando il campo sorgente è vuoto: in compilazione il campo
     * resta visibile, per non far sparire un dato che si sta inserendo.
     *
     * @param  string  $attribute  l'attributo del campo da cui questo dipende
     * @param  array<int, string|int>  $values  i valori che rendono questo campo pertinente
     * @param  ?Closure  $message  messaggio di errore; riceve nulla e torna una stringa
     * @return $this
     */
    public function visibleWhen(string $attribute, array $values, ?Closure $message = null)
    {
        $wanted = array_map(fn ($v) => (string) $v, $values);
        $satisfied = function ($value) use ($wanted): bool {
            $chosen = self::valuesOf($value);

            return $chosen === [] || array_intersect($chosen, $wanted) !== [];
        };

        $this->dependsOn([$attribute], function ($field, NovaRequest $request, $formData) use ($attribute, $satisfied) {
            $satisfied($formData->get($attribute)) ? $field->show() : $field->hide();
        });

        // L'array non è casuale: una Closure nuda Nova la prende per un risolutore di regole e
        // la invoca con la NovaRequest (HandlesValidation::getRules), e la pagina va in 500.
        return $this->rules([function ($attr, $value, $fail) use ($attribute, $satisfied, $message) {
            if (self::valuesOf($value) === []) {
                return;
            }
            if (! $satisfied(request()->input($attribute))) {
                $fail($message ? $message() : __('This field does not apply with the current selection.'));
            }
        }]);
    }

    /**
     * I valori scelti in un campo: una select singola manda un valore, una multipla la lista
     * separata da virgole, e un POST a mano può mandare un JSON.
     *
     * @return list<string>
     */
    public static function valuesOf(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        return collect((array) $value)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v) => (string) $v)
            ->values()
            ->all();
    }
}
