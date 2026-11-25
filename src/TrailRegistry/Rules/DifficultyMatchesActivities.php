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
 * La famiglia di ogni attivita' e' dichiarata in `ACTIVITY_FAMILIES`; i livelli
 * non si scrivono nel codice, si leggono da `taxonomy_terms`: il livello e' un
 * termine con identifier `<famiglia>-<livello>` il cui padre (`parent`, come in
 * `TaxonomyAbleModel`) e' quella famiglia. Il suffisso dell'identifier e' il
 * valore che l'API riceve, cosi' come arriva: `mtb-difficulty-BC` per `BC`,
 * `horse-difficulty-easy` per `easy`. Le sigle delle scale ufficiali (CAI, MTB,
 * accessibilita') restano maiuscole perche' sono una convenzione; dove
 * l'etichetta era una parola italiana — ippovia, nordic walking, sentieri
 * didattici, mobilita' dolce — il valore e' un codice inglese minuscolo, perche'
 * «Facile» non e' una convenzione ma una label, che in Nova si puo' rinominare.
 *
 * Il nome del termine non entra mai nel confronto: e' cio' che si legge in Nova,
 * e cambia senza preavviso.
 *
 * `trekking` e `hiking` condividono la scala CAI (T, E, EE, EEA): nel materiale
 * concordato con il cliente «Trekking / Hiking» e' una scala sola, quindi accettano
 * esattamente gli stessi livelli. Condividono la scala, non il livello scelto: lo
 * stesso sentiero puo' essere `E` per il trekking e `EEA` per l'escursionismo, e
 * dichiararli diversi non e' un errore.
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

    /**
     * La famiglia di difficolta' di ogni attivita': e' la mappa della Rule,
     * esposta perche' chi mostra le scale (l'Action di approvazione) non la
     * duplichi. Non si deriva dal nome: `accessible-trails` punta a
     * `inclusivity-difficulty`.
     *
     * @return array<string, string>
     */
    public static function activityFamilies(): array
    {
        return static::ACTIVITY_FAMILIES;
    }

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

            if (! is_string($level) || $level === '') {
                $fail('validation.in')->translate(['attribute' => $attribute.'.'.$activity]);

                return;
            }

            if (! $this->levelBelongsToFamily($family, $level)) {
                $fail('validation.in')->translate(['attribute' => $attribute.'.'.$activity]);

                return;
            }
        }
    }

    protected function familyExists(string $family): bool
    {
        return TaxonomyTerm::query()->taxonomies()->where('identifier', $family)->exists();
    }

    /**
     * Il livello appartiene alla famiglia se esiste il termine
     * `<famiglia>-<livello>` e se il suo padre e' quella famiglia: l'identifier
     * da solo non basta, perche' lo stesso suffisso puo' esistere altrove.
     */
    protected function levelBelongsToFamily(string $family, string $level): bool
    {
        return TaxonomyTerm::query()
            ->where('identifier', $family.'-'.$level)
            ->whereHas('parent', fn ($families) => $families->where('identifier', $family))
            ->exists();
    }
}
