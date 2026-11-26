<?php

namespace Wm\WmPackage\TrailRegistry;

/**
 * I controlli che guardano piu' campi insieme, fuori da ogni FormRequest.
 *
 * Stavano in `StoreApplicationRequest::withValidator()`, che lavora
 * sull'input HTTP di una chiamata. Da quando la domanda si compila in piu'
 * riprese servono in tre momenti diversi — la creazione, una modifica, la
 * conferma — e alla conferma non c'e' nessun input HTTP: c'e' `api_data`
 * letto dal database. Da qui l'estrazione: qui dentro si ragiona su array, e
 * chi chiama ci porta cio' che ha.
 *
 * I due metodi rispondono a due domande diverse, e non si sostituiscono:
 * `linkedFieldErrors()` guarda cosa la chiamata porta con se', ed e' una
 * regola del protocollo; `valueErrors()` guarda i valori, ed e' una regola
 * del dominio.
 */
class ApplicationConsistency
{
    /**
     * I tre campi che si tengono per mano: chi ne manda uno manda anche gli
     * altri, nella stessa chiamata.
     *
     * Senza questa regola nessuno dei due si potrebbe cambiare per primo:
     * cambiare `activities` renderebbe incoerente la primaria gia' salvata, e
     * cambiare la primaria la renderebbe incoerente con le `activities` gia'
     * salvate. Chiedendoli insieme non c'e' un ordine da rispettare, e cio'
     * che e' salvato resta sempre coerente.
     *
     * `difficulty` e' facoltativo: puo' mancare, ma se c'e' tira dentro le
     * `activities`.
     *
     * @param  list<string>  $declared  i campi che la chiamata porta con se'
     * @return array<string, list<string>> errori per campo
     */
    public function linkedFieldErrors(array $declared): array
    {
        $errors = [];
        $ha = static fn (string $field): bool => in_array($field, $declared, true);

        if ($ha('primary_activity') && ! $ha('activities')) {
            $errors['activities'][] = __('The proposed activities are required when the primary activity is declared');
        }

        if ($ha('activities') && ! $ha('primary_activity')) {
            $errors['primary_activity'][] = __('The primary activity is required when the proposed activities are declared');
        }

        if ($ha('difficulty') && ! $ha('activities')) {
            $errors['activities'][] = __('The proposed activities are required when a difficulty is declared');
        }

        return $errors;
    }

    /**
     * I valori stanno fra loro: l'attivita' primaria e' una di quelle
     * proposte, e ogni difficolta' parla di un'attivita' proposta.
     *
     * Il confronto si fa solo su campi che hanno gia' la forma giusta: con un
     * campo malformato l'errore vero e' un altro, e questo lo coprirebbe. Chi
     * chiama passa percio' i campi di cui la validazione di forma si e' gia'
     * detta soddisfatta, oppure tutti, se non c'e' stata validazione.
     *
     * @param  array<string, mixed>  $data  i campi su cui giudicare
     * @param  list<string>  $skip  campi gia' in errore, da non rigiudicare
     * @return array<string, list<string>> errori per campo
     */
    public function valueErrors(array $data, array $skip = []): array
    {
        $errors = [];
        $activities = $data['activities'] ?? null;

        if (! is_array($activities)) {
            return $errors;
        }

        $primary = $data['primary_activity'] ?? null;

        if (! in_array('primary_activity', $skip, true)
            && is_string($primary)
            && ! in_array($primary, $activities, true)) {
            $errors['primary_activity'][] = __('The primary activity :activity is not among the proposed activities', ['activity' => $primary]);
        }

        $difficulty = $data['difficulty'] ?? null;

        if (! in_array('difficulty', $skip, true) && is_array($difficulty)) {
            foreach (array_keys($difficulty) as $activity) {
                if (! in_array($activity, $activities, true)) {
                    $errors['difficulty.'.$activity][] = __('The difficulty is declared for :activity, which is not among the proposed activities', ['activity' => $activity]);
                }
            }
        }

        return $errors;
    }
}
