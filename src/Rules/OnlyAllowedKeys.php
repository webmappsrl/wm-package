<?php

namespace Wm\WmPackage\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;

/**
 * Un array puo' portare solo le chiavi concordate: ogni altra chiave fa
 * fallire la validazione, e il messaggio la nomina.
 *
 * Serve dove il contratto e' scritto e il mittente e' uno solo — per esempio
 * un canale API verso un ente — e una chiave inattesa non va ne' ignorata ne'
 * scartata in silenzio, ma segnalata a chi integra mentre sta integrando.
 * Laravel non ha un equivalente: `array:name,email,subject` rifiuta la chiave
 * in piu' ma risponde «deve essere una lista», senza dire quale chiave sia il
 * problema, e `validation.array` non ha un segnaposto per elencarle.
 *
 * Questa regola giudica **solo le chiavi**. I valori restano alle regole
 * dichiarate accanto (`in:`, `email`, `string`): la' spiegano gia' cosa non va
 * e, nel caso di `in:`, da li' la documentazione OpenAPI ricava l'elenco dei
 * valori ammessi, che altrimenti andrebbe riscritto a mano.
 *
 * L'errore non si attacca all'array ma alla chiave di troppo, cosi' chi
 * integra legge nel percorso il campo, la posizione nella lista e il nome
 * della chiave da togliere: `managing_subjects.0.cf`.
 */
class OnlyAllowedKeys implements ValidationRule, ValidatorAwareRule
{
    protected Validator $validator;

    /**
     * @param  list<string>  $allowed  le chiavi concordate
     */
    public function __construct(protected array $allowed) {}

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Un valore che non e' un array lo rifiuta la regola `array` accanto:
        // aggiungere qui un secondo messaggio sullo stesso errore direbbe due
        // volte la stessa cosa con parole diverse.
        if (! is_array($value)) {
            return;
        }

        foreach (array_keys($value) as $key) {
            if (in_array($key, $this->allowed, true)) {
                continue;
            }

            $this->validator->errors()->add(
                $attribute.'.'.$key,
                __('The key :key is not expected here. The agreed keys are: :allowed.', [
                    'key' => $key,
                    'allowed' => implode(', ', $this->allowed),
                ])
            );
        }
    }
}
