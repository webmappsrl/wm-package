<?php

namespace Wm\WmPackage\Http\Requests\TrailRegistry;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

// Validazione della creazione di una domanda di accatastamento.
//
// Le regole dei campi del modulo derivano dallo schema (`ApplicationSchema`),
// risolto dal container perche' lo shard puo' ridefinirlo; a quelle si
// aggiungono `name`, `geometry` e i vincoli di plausibilita' dei valori
// numerici.
//
// Nessuna regola vieta le chiavi che lo schema non dichiara: se il cliente
// aggiunge un campo al proprio modulo, il dato deve arrivare comunque e
// finire nel payload conservato, invece di essere scartato in silenzio.
// Per questo il payload da conservare si legge con `apiData()` e non con
// `validated()`, che restituirebbe solo i campi con una regola.
//
// `DifficultyMatchesActivities` puo' sollevare `MissingDifficultyFamilyException`
// quando le scale di difficolta' non sono a database. Non la si intercetta
// qui: e' un guasto di configurazione dell'installazione, non un errore di chi
// compila, e deve risalire fino all'handler delle eccezioni (risposta 5xx e
// segnalazione), non diventare un 422 che farebbe correggere al richiedente
// qualcosa che non ha sbagliato.

/**
 * I dati della domanda di accatastamento e il file del tracciato (`geometry`),
 * in `multipart/form-data`. I campi del modulo sono quelli dello schema, con
 * il numero di campo del format nella descrizione di ciascuno.
 */
class StoreApplicationRequest extends FormRequest
{
    /**
     * Campi della richiesta che non fanno parte del payload dello schema.
     *
     * @var list<string>
     */
    protected const NON_SCHEMA_INPUT = ['name', 'geometry'];

    /** Il limite del file del tracciato, in kilobyte come vuole la regola `max`. */
    public const MAX_GEOMETRY_KILOBYTES = 5120;

    /**
     * Intervalli di plausibilita' dei campi numerici, `[min, max]`. Servono a
     * scartare valori palesemente errati (un'unita' di misura sbagliata, un
     * refuso) e non a fissare un'unita': quelle non sono ancora concordate,
     * quindi i limiti sono larghi di proposito.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    protected const PLAUSIBLE_RANGES = [
        'distance' => [0, 1000],
        'ascent' => [0, 20000],
        'duration_forward' => [0, 10000],
        'duration_backward' => [0, 10000],
        'maintenance_frequency_months' => [1, 120],
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * La chiamata e' `multipart/form-data` (la traccia e' un file, nella stessa
     * chiamata dei dati) e in multipart ogni valore e' una stringa: i campi
     * strutturati arrivano come JSON (`activities` = `["hiking","mtb"]`,
     * `difficulty` = `{"hiking":"EE"}`) e i booleani come `true`/`false`.
     * Qui si decodificano prima che le regole li guardino.
     *
     * L'elenco dei campi da decodificare si ricava dal tipo dichiarato nello
     * schema (`json`, `boolean`), non e' scritto qui: se un campo cambia tipo,
     * la decodifica lo segue. Un JSON malformato non solleva nulla: il campo
     * resta stringa e la regola `array` lo rifiuta con un errore di validazione.
     */
    protected function prepareForValidation(): void
    {
        $decoded = [];

        foreach (app(ApplicationSchema::class)->fields() as $field) {
            if (! $this->has($field->name)) {
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
                // La stringa vuota e' un campo non compilato, non un `false`:
                // `filter_var` la leggerebbe come falso e supererebbe `required`.
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
        $rules = app(ApplicationSchema::class)->rules();

        foreach (static::PLAUSIBLE_RANGES as $field => [$min, $max]) {
            if (isset($rules[$field])) {
                $rules[$field][] = "between:{$min},{$max}";
            }
        }

        return $rules + [
            'name' => ['required', 'string', 'max:255'],
            // 5 MB, il limite che il format fissa per ogni file del fascicolo,
            // traccia compresa (campo 68). Il sentiero piu' denso del Catasto
            // pesa sotto 1 MB in GPX: il margine copre anche una traccia
            // registrata sul campo a un punto al secondo.
            'geometry' => ['required', 'file', 'max:'.self::MAX_GEOMETRY_KILOBYTES],
        ];
    }

    /**
     * Una difficolta' dichiarata per un'attivita' che il richiedente non ha
     * indicato in `activities` e' incoerente. La Rule del campo vede solo il
     * valore di `difficulty`; il confronto con le attivita' si puo' fare solo
     * qui, dove c'e' l'intero payload. Si fa solo se i due campi sono
     * validi: altrimenti l'errore vero e' un altro e questo lo confonderebbe.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['activities', 'activities.*', 'difficulty'])) {
                return;
            }

            $difficulty = $this->input('difficulty');
            $activities = $this->input('activities');

            if (! is_array($difficulty) || ! is_array($activities)) {
                return;
            }

            foreach (array_keys($difficulty) as $activity) {
                if (! in_array($activity, $activities, true)) {
                    $validator->errors()->add(
                        'difficulty.'.$activity,
                        __('The difficulty is declared for :activity, which is not among the proposed activities', ['activity' => $activity])
                    );
                }
            }
        });
    }

    /**
     * Il payload da conservare per intero: tutto cio' che e' arrivato nel
     * corpo della richiesta, chiavi non previste dallo schema comprese e con
     * i campi strutturati gia' decodificati da `prepareForValidation()`,
     * tranne `name` e `geometry`, che hanno la loro destinazione.
     *
     * Parte dal solo corpo (`getInputSource()`) e non da `all()`: quello
     * include la query string e i file caricati. La query string non e' cio'
     * che il richiedente ha dichiarato e non deve finire nel verbale; un file
     * sotto una chiave qualsiasi diventerebbe un `UploadedFile` che, salvato,
     * si riduce a `{}`: un dato che arriva e sparisce in silenzio.
     *
     * @return array<string, mixed>
     */
    public function apiData(): array
    {
        return Arr::except($this->getInputSource()->all(), static::NON_SCHEMA_INPUT);
    }

    /**
     * I nomi dei campi nei messaggi di errore sono quelli del payload.
     *
     * Laravel, lasciato fare, scrive «duration forward» al posto di
     * `duration_forward`: per una persona e' piu' leggibile, per chi integra e'
     * un nome che nel payload non esiste e che non si puo' cercare nella
     * documentazione. Qui ogni campo risponde con se stesso.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $names = array_map(
            fn (string $key) => explode('.', $key)[0],
            array_keys($this->rules()),
        );

        return array_combine($names, $names);
    }
}
