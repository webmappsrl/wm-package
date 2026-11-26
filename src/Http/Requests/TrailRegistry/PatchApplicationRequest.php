<?php

namespace Wm\WmPackage\Http\Requests\TrailRegistry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Wm\WmPackage\Http\Requests\TrailRegistry\Concerns\AuthorizesApiChannelApplication;
use Wm\WmPackage\Http\Requests\TrailRegistry\Concerns\BuildsApplicationFieldRules;
use Wm\WmPackage\TrailRegistry\ApplicationConsistency;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

/**
 * Una modifica a una domanda in compilazione: i campi che cambiano, e
 * volendo un tracciato nuovo.
 *
 * Le regole sono quelle della creazione, con due differenze che vengono
 * entrambe dal compilare in piu' riprese:
 *
 * - **si valida l'unione** di cio' che e' gia' salvato e di cio' che arriva,
 *   non il solo corpo della chiamata: un campo che si confronta con un altro
 *   deve poterlo trovare anche se e' stato consegnato prima;
 * - **l'obbligatorieta' non vale qui**: un campo del modulo non ancora
 *   compilato non fa fallire la chiamata, e lo pretendera' la conferma. Resta
 *   invece per le regole annidate, che descrivono com'e' fatto un elemento di
 *   una lista: una lista consegnata a meta' e' sempre un errore.
 *
 * La cancellazione si chiede mandando il campo senza valore. Attenzione a
 * cosa arriva davvero: `ConvertEmptyStringsToNull` e' un middleware globale,
 * quindi quel campo arriva `null` e non come stringa vuota, e senza
 * intercettarlo verrebbe scritto come valore invece di togliere la chiave.
 */
class PatchApplicationRequest extends FormRequest
{
    use AuthorizesApiChannelApplication;
    use BuildsApplicationFieldRules;

    /** Il limite del file del tracciato, lo stesso della creazione. */
    public const MAX_GEOMETRY_KILOBYTES = StoreApplicationRequest::MAX_GEOMETRY_KILOBYTES;

    /** Campi della chiamata che non fanno parte del payload dello schema. */
    protected const NON_SCHEMA_INPUT = ['name', 'geometry'];

    /**
     * Chiavi del protocollo, non del modulo: viaggiano nel corpo ma non sono
     * cose che il richiedente dichiara.
     *
     * `_method` e' il verbo dichiarato nel form, `_schema_version` la scrive
     * chi accetta e sta gia' dentro il payload salvato.
     */
    protected const PROTOCOL_KEYS = ['_method', ApplicationSchema::VERSION_KEY];

    /** @var list<string> i campi che la chiamata chiede di cancellare */
    protected array $deleted = [];

    /**
     * Decodifica i campi strutturati come fa la creazione, e mette da parte
     * le cancellazioni prima che il validatore le veda.
     */
    protected function prepareForValidation(): void
    {
        $decoded = [];

        foreach (app(ApplicationSchema::class)->fields() as $field) {
            if (! $this->has($field->name)) {
                continue;
            }

            // Chiave presente, valore assente: e' una cancellazione. Esce dal
            // payload da validare, altrimenti su un campo `nullable`
            // passerebbe e verrebbe scritta come valore.
            if ($this->input($field->name) === null) {
                $this->deleted[] = $field->name;

                continue;
            }

            $value = $this->input($field->name);

            if (! is_string($value)) {
                continue;
            }

            if ($field->type === 'json') {
                $json = json_decode($value, true);

                if (is_array($json)) {
                    $decoded[$field->name] = $json;
                }
            } elseif ($field->type === 'boolean' && trim($value) !== '') {
                $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($boolean !== null) {
                    $decoded[$field->name] = $boolean;
                }
            }
        }

        $this->merge($decoded);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = $this->applicationFieldRules();

        return $rules + [
            'name' => ['sometimes', 'string', 'max:255'],
            'geometry' => ['sometimes', 'file', 'max:'.self::MAX_GEOMETRY_KILOBYTES],
        ];
    }

    /**
     * Cio' su cui le regole girano: il payload gia' salvato con sopra quello
     * che arriva, senza i campi che la chiamata cancella.
     *
     * E' qui che «si valida l'unione» diventa vero, e non nelle regole.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $saved = (array) ($this->application()->api_data ?? []);

        foreach (static::PROTOCOL_KEYS as $key) {
            unset($saved[$key]);
        }

        $union = array_merge($saved, $this->bodyFields());

        foreach ($this->deleted as $field) {
            unset($union[$field]);
        }

        return $union;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->rejectUndeclaredKeys($validator);
            $this->rejectDeletionOfRequiredFields($validator);

            $consistency = app(ApplicationConsistency::class);

            // I campi legati si guardano su cio' che la chiamata porta, non
            // sull'unione: e' una regola su come si consegna.
            foreach ($consistency->linkedFieldErrors(array_keys($this->bodyFields())) as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }

            if ($validator->errors()->hasAny(['activities', 'activities.*'])) {
                return;
            }

            $skip = [];

            foreach (['primary_activity', 'difficulty'] as $field) {
                if ($validator->errors()->has($field)) {
                    $skip[] = $field;
                }
            }

            foreach ($consistency->valueErrors($this->validationData(), $skip) as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        });
    }

    /**
     * Un campo obbligatorio **gia' compilato** non si riporta a vuoto: un
     * valore sbagliato si corregge sostituendolo.
     *
     * Su un campo obbligatorio mai compilato la cancellazione non e' un
     * errore e non fa niente: chi rimanda l'intero modulo, con dentro anche i
     * campi ancora vuoti, non deve ricevere un errore per ciascuno.
     */
    protected function rejectDeletionOfRequiredFields(Validator $validator): void
    {
        $required = app(ApplicationSchema::class)->requiredFieldNames();
        $saved = (array) ($this->application()->api_data ?? []);

        foreach ($this->deleted as $field) {
            if (! in_array($field, $required, true)) {
                continue;
            }

            $value = $saved[$field] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $validator->errors()->add(
                $field,
                __('A required field cannot be emptied: correct it by sending a new value')
            );
        }
    }

    /**
     * Le chiavi non dichiarate dallo schema non passano, come alla creazione.
     * Si guarda il solo corpo: nell'unione ci sono anche le chiavi che
     * abbiamo scritto noi, che nessuno ha dichiarato.
     */
    protected function rejectUndeclaredKeys(Validator $validator): void
    {
        $declared = [];

        foreach (array_keys($this->rules()) as $rule) {
            $declared[strtok((string) $rule, '.')] = true;
        }

        foreach (array_keys($this->bodyFields()) as $key) {
            if (! isset($declared[$key])) {
                $validator->errors()->add(
                    (string) $key,
                    __('The field :key is not expected by this API.', ['key' => $key])
                );
            }
        }
    }

    /**
     * I campi del modulo che la chiamata porta: il corpo senza le chiavi del
     * protocollo e senza quelle che hanno una destinazione propria.
     *
     * @return array<string, mixed>
     */
    public function bodyFields(): array
    {
        $body = $this->getInputSource()->all();

        foreach ([...static::PROTOCOL_KEYS, ...static::NON_SCHEMA_INPUT] as $key) {
            unset($body[$key]);
        }

        return $body;
    }

    /** @return list<string> i campi che la chiamata chiede di cancellare */
    public function deletedFields(): array
    {
        return $this->deleted;
    }

    /**
     * I messaggi che elencano i valori concordati.
     *
     * Il messaggio di `in:` tradotto da Laravel dice «Il valore selezionato
     * per X non e' valido», che non aiuta chi integra a capire cosa mandare.
     * L'elenco resta dichiarato una volta sola nelle regole: `:values` lo
     * ripete, e da li' la documentazione OpenAPI ricava il proprio `enum`.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['in' => __('The value of :attribute is not among the agreed ones: :values.')];
    }

    public function attributes(): array
    {
        $names = array_map(
            fn (string $key) => explode('.', $key)[0],
            array_keys($this->rules()),
        );

        return array_combine($names, $names);
    }

    public function application(): TrailApplication
    {
        return $this->route('application');
    }
}
