<?php

namespace Wm\WmPackage\TrailRegistry\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Exceptions\MissingDifficultyFamilyException;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

/**
 * Valida la difficolta' proposta di una domanda: una mappa attivita' -> livello
 * (`{"hiking": "EE", "mtb": "BC"}`), non un valore singolo. Ogni attivita' deve
 * essere fra quelle ammesse dallo schema e ogni livello deve essere un termine
 * della famiglia di difficolta' di quell'attivita': `EEA` esiste come livello, ma
 * non e' valido per `mtb`.
 *
 * La famiglia di ogni attivita' e' dichiarata in `ACTIVITY_FAMILIES`; i termini
 * non si scrivono nel codice, si leggono da `taxonomy_terms`: il livello e' un
 * termine la cui famiglia (`parent`, come in `TaxonomyAbleModel`) ha quell'identifier,
 * e si riconosce dal nome italiano (`EE`, `BC`, `AEE`), che e' cio' che il format
 * riporta, non dal suo identifier.
 *
 * `trekking` e `hiking` condividono la scala CAI (T, E, EE, EEA): nel materiale
 * concordato con il cliente «Trekking / Hiking» e' una scala sola, quindi accettano
 * esattamente gli stessi livelli.
 *
 * `soft-mobility-difficulty` non compare nella mappa di proposito: la mobilita'
 * dolce non e' una delle attivita' del modulo di domanda, e i suoi livelli non sono
 * validi per nessuna di esse. Se un giorno diventera' un'attivita', si aggiunge una
 * riga alla mappa e alla lista dello schema.
 *
 * Una famiglia assente a database non e' un errore di chi compila la domanda ma un
 * problema di configurazione dell'installazione (le scale non sono state
 * seminate): in quel caso la Rule solleva `MissingDifficultyFamilyException`
 * invece di rifiutare il valore.
 */
class DifficultyMatchesActivities implements ValidationRule
{
    /**
     * Identifier della famiglia di difficolta' di ogni attivita'.
     *
     * @var array<string, string>
     */
    protected const ACTIVITY_FAMILIES = [
        'trekking' => 'hiking-difficulty',
        'hiking' => 'hiking-difficulty',
        'mtb' => 'mtb-difficulty',
        'horse' => 'horse-difficulty',
        'accessible-trails' => 'inclusivity-difficulty',
        'nordic-walking' => 'nordic-walking-difficulty',
        'educational-trails' => 'educational-trails-difficulty',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('validation.array')->translate();

            return;
        }

        $activities = app(ApplicationSchema::class)->activities();

        foreach ($value as $activity => $level) {
            $family = is_string($activity) && in_array($activity, $activities, true)
                ? (static::ACTIVITY_FAMILIES[$activity] ?? null)
                : null;

            if ($family === null) {
                $fail('validation.in')->translate(['attribute' => $attribute]);

                return;
            }

            if (! $this->familyExists($family)) {
                throw new MissingDifficultyFamilyException($family, $activity);
            }

            if (! is_string($level) || $level === '' || ! $this->levelBelongsToFamily($family, $level)) {
                $fail('validation.in')->translate(['attribute' => $attribute.'.'.$activity]);

                return;
            }
        }
    }

    protected function familyExists(string $family): bool
    {
        return TaxonomyTerm::query()->taxonomies()->where('identifier', $family)->exists();
    }

    protected function levelBelongsToFamily(string $family, string $level): bool
    {
        return TaxonomyTerm::query()
            ->where('name->it', $level)
            ->whereHas('parent', fn ($families) => $families->where('identifier', $family))
            ->exists();
    }
}
