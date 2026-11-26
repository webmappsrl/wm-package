<?php

namespace Wm\WmPackage\Http\Requests\TrailRegistry;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validazione dell'apertura di una domanda di accatastamento.
 *
 * L'apertura chiede tre cose e **nessun campo del modulo**: il riferimento
 * della pratica presso chi consegna, il nome e il file del tracciato. Il
 * modulo si consegna dopo, con una o piu' modifiche.
 *
 * Non e' solo «non obbligatorio»: un campo del modulo mandato qui viene
 * rifiutato. Le due chiamate hanno cosi' un compito ciascuna — questa apre il
 * fascicolo e prenota il numero, la modifica lo riempie — e chi integra non
 * deve scegliere fra due strade che fanno la stessa cosa. A rifiutarli e' il
 * controllo sulle chiavi non dichiarate, che c'e' gia': qui non si dichiara
 * nessun campo del modulo, quindi nessuno passa.
 */
class StoreApplicationRequest extends FormRequest
{
    /**
     * Campi della richiesta che non fanno parte del payload dello schema.
     *
     * @var list<string>
     */
    protected const NON_SCHEMA_INPUT = ['name', 'geometry', 'reference'];

    /** Il limite del file del tracciato, in kilobyte come vuole la regola `max`. */
    public const MAX_GEOMETRY_KILOBYTES = 5120;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Il riferimento con cui chi consegna chiama la pratica a casa
            // propria. Obbligatorio solo dove un sistema esterno c'e'
            // davvero: lo dice la configurazione del dominio, spenta di
            // default, perche' uno shard puo' accendere il Catasto e creare
            // domande solo d'ufficio.
            //
            // Niente regola `unique` qui: un riferimento gia' usato non e' un
            // errore di forma ma una domanda che esiste, e la risposta deve
            // nominarla. Se ne occupa il controller.
            'reference' => [
                config('wm-package.features.trail_registry.api.require_reference', false)
                    ? 'required'
                    : 'sometimes',
                'string',
                'max:255',
            ],
            // 5 MB, il limite che il format fissa per ogni file del fascicolo,
            // traccia compresa (campo 68). Il sentiero piu' denso del Catasto
            // pesa sotto 1 MB in GPX: il margine copre anche una traccia
            // registrata sul campo a un punto al secondo.
            'geometry' => ['required', 'file', 'max:'.self::MAX_GEOMETRY_KILOBYTES],
        ];
    }

    /**
     * L'unico controllo che resta: le chiavi che non ci si aspetta.
     *
     * I campi del modulo non appartengono a questa chiamata, quindi qui non
     * c'e' niente da confrontare fra loro: i vincoli che guardano piu' campi
     * insieme vivono in `ApplicationConsistency`, dove li usano la modifica e
     * la conferma.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->rejectUndeclaredKeys($validator));
    }

    /**
     * Il payload da conservare: vuoto.
     *
     * L'apertura non porta campi del modulo, quindi `api_data` nasce con la
     * sola versione dello schema, che ci scrive il controller. Il metodo
     * resta perche' e' il controller a decidere cosa va nella colonna, e
     * perche' uno shard che ridefinisse questa richiesta possa consegnare
     * altro.
     *
     * @return array<string, mixed>
     */
    public function apiData(): array
    {
        return [];
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
    /**
     * Ogni chiave che lo schema non dichiara fa fallire la chiamata, e il
     * messaggio la nomina.
     *
     * Il canale ha un mittente solo e un contratto scritto: una chiave
     * inattesa e' quasi sempre un refuso o un campo della forma precedente, e
     * tacere la farebbe sparire dentro `api_data` mentre chi integra crede di
     * averla consegnata. Laravel non lo fa da se': il validator percorre le
     * regole, mai i dati, quindi una chiave senza regola non la vede nessuno.
     *
     * Il controllo gira in `after()`, cioe' dopo `prepareForValidation()`: i
     * campi strutturati arrivano qui gia' decodificati.
     */
    protected function rejectUndeclaredKeys(Validator $validator): void
    {
        $declared = [];

        foreach (array_keys($this->rules()) as $rule) {
            $declared[strtok($rule, '.')] = true;
        }

        // Solo il corpo: la query string e i file sotto chiavi non previste
        // li scarta gia' `apiData()`, e non sono cio' di cui parla il
        // contratto dei campi.
        foreach (array_keys($this->getInputSource()->all()) as $key) {
            if (! isset($declared[$key])) {
                $validator->errors()->add(
                    (string) $key,
                    __('The field :key is not expected by this API.', ['key' => $key])
                );
            }
        }
    }

    public function attributes(): array
    {
        $names = array_map(
            fn (string $key) => explode('.', $key)[0],
            array_keys($this->rules()),
        );

        return array_combine($names, $names);
    }
}
