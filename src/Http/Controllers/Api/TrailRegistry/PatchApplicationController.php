<?php

namespace Wm\WmPackage\Http\Controllers\Api\TrailRegistry;

use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Http\Requests\TrailRegistry\PatchApplicationRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailGeometryException;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorExhaustedException;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorNotFoundException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;
use Wm\WmPackage\TrailRegistry\Services\ApplicationDemService;
use Wm\WmPackage\TrailRegistry\TrailGeometryReader;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * La modifica di una domanda in compilazione.
 *
 * La chiamata e' un `POST` con `_method=PATCH` nel modulo, e non un `PATCH`
 * vero: non per gusto, ma per portabilita'. Fino a PHP 8.3 il corpo
 * `multipart/form-data` di un `PATCH` restava grezzo in `php://input` e
 * Laravel non vedeva ne' i campi ne' il file (bug #55815 del bug tracker di
 * PHP); da PHP 8.4 Symfony lo legge con `request_parse_body()` e un `PATCH`
 * vero funzionerebbe, ma il package dichiara ancora PHP 8.1 e deve girare
 * anche dove quel parsing non c'e'. Il method spoofing e' il meccanismo
 * nativo di Laravel e resta coerente con il canale, che usa gia' `POST` per
 * tutte le scritture.
 */
#[Group(
    name: 'Applications',
    description: 'Creazione e compilazione delle domande di accatastamento.',
    weight: 3,
)]
class PatchApplicationController extends Controller
{
    use ResolvesConfiguredApplication;

    public function __construct(
        protected TrailGeometryReader $geometryReader,
        protected ApplicationDemService $demService,
        protected TrailRegistryService $registry,
    ) {}

    /**
     * Modifica la domanda
     *
     * Cambia i campi di una domanda ancora in compilazione, e volendo
     * sostituisce il tracciato. Si può chiamare quante volte si vuole, un
     * campo per volta o molti insieme, finché la domanda non viene confermata.
     *
     * ### Il verbo: un POST che dichiara PATCH
     *
     * La chiamata si fa con **`POST`**, aggiungendo al modulo un campo
     * `_method` con valore `PATCH`. Non è un vezzo: la chiamata può portare il
     * file del tracciato, e un `PATCH` con `multipart/form-data` non è letto
     * da tutte le versioni di PHP su cui questo codice gira. Il campo
     * `_method` è il meccanismo con cui Laravel riconosce il verbo vero, e la
     * chiamata viene trattata come una `PATCH` a tutti gli effetti.
     *
     * ### Cosa cambia e cosa resta
     *
     * La semantica è quella di **RFC 7396 (JSON Merge Patch)**:
     *
     * - un campo **non mandato** resta com'era;
     * - un campo **mandato** sostituisce il valore di prima;
     * - un campo **mandato senza valore** cancella il valore;
     * - una **lista si sostituisce per intero**: per cambiare un soggetto
     *   gestore si rimanda l'intera lista dei gestori, non il solo elemento
     *   cambiato. Vale anche per `difficulty`, che si rimanda tutta insieme.
     *
     * **La cancellazione si fa con il campo vuoto, non con `null`**, e qui ci
     * si allontana dalla lettera della RFC: la chiamata è
     * `multipart/form-data`, dove ogni valore è testo e `null` non esiste.
     *
     * **Un campo obbligatorio già compilato non si può svuotare:** un valore
     * sbagliato si corregge mandandone uno nuovo. Lo stesso campo, se non è
     * mai stato compilato, può essere mandato vuoto senza errore — serve a chi
     * rimanda l'intero modulo, con dentro anche i campi ancora da riempire.
     *
     * ### Cosa viene controllato
     *
     * Si valida **l'unione** di ciò che è già salvato e di ciò che arriva, con
     * le stesse regole della creazione. I campi obbligatori non ancora
     * compilati non fanno fallire la chiamata: li pretende la conferma.
     *
     * Se un campo non va bene la risposta è `422` con l'elenco dei campi, e
     * **non viene scritto nulla**, nemmeno i campi corretti della stessa
     * chiamata.
     *
     * **Tre campi si mandano insieme:** `activities`, `primary_activity` e
     * `difficulty`. L'attività primaria deve essere una di quelle proposte e
     * le difficoltà devono parlare delle stesse attività, quindi cambiarne uno
     * solo lasciando gli altri com'erano darebbe una combinazione incoerente.
     * Mandandoli nella stessa chiamata non c'è un ordine da rispettare.
     *
     * ### Come viaggiano i campi
     *
     * La chiamata è `multipart/form-data`, e lì ogni valore è una stringa: i
     * campi strutturati si possono mandare in due modi equivalenti —
     * **liste e mappe** come stringa JSON (`activities` = `["hiking","mtb"]`,
     * `difficulty` = `{"hiking":"EE"}`), oppure nella forma nativa dei form
     * (`activities[]=hiking`, `activities[]=mtb`, `difficulty[hiking]=EE`).
     *
     * Un JSON malformato non è un errore a sé: il campo resta una stringa e
     * la validazione risponde `422` indicando il campo.
     *
     * ### Unità: qui non sono quelle del format
     *
     * Il format chiede la lunghezza in metri e i tempi di percorrenza nel
     * formato `ORE:MINUTI`. **In questa chiamata viaggiano diversamente**:
     *
     * - `distance`: in **chilometri**, con un decimale (`12.5`, non `12500`);
     * - `duration_forward` e `duration_backward`: in **minuti interi**
     *   (`240`, non `4:00`);
     * - `ascent`: in metri, come nel format.
     *
     * Un valore palesemente fuori scala, per esempio una lunghezza in metri
     * (`distance` accetta da 0 a 1000), è rifiutato con `422`.
     *
     * ### Il tracciato
     *
     * Si sostituisce mandando `geometry` come nella creazione. Quando si fa,
     * i dati tecnici vengono ricalcolati da capo — `dem_status` dice se sono
     * già pronti o se bisogna rileggerli più tardi da
     * `GET /trail-applications/{id}` — e il numero del catasto viene rivisto:
     * se il tracciato nuovo cade in un altro settore, quello riservato viene
     * liberato e se ne riserva un altro. Il numero non è mai uscito da qui,
     * quindi la sostituzione non ha conseguenze per chi integra.
     *
     * ### Esempio
     *
     * `$BASE_URL` è l'URL di base del canale, indicato nell'introduzione.
     *
     * ```
     * curl -X POST "$BASE_URL/trail-applications/$ID" \
     *   -H "Authorization: Bearer $TOKEN" \
     *   -H "Accept: application/json" \
     *   -F "_method=PATCH" \
     *   -F "distance=12.5" \
     *   -F 'activities=["hiking","mtb"]' \
     *   -F "primary_activity=hiking" \
     *   -F 'difficulty={"hiking":"EE"}' \
     *   -F "overlapping_routes="
     * ```
     *
     * L'ultima riga cancella il valore di `overlapping_routes`.
     */
    #[BodyParameter('_method', description: 'Vale sempre `PATCH`. Dichiara il verbo vero di una chiamata inviata come `POST`.', example: 'PATCH')]
    #[BodyParameter('name', description: 'Nome con cui la domanda è identificata. Facoltativo: si manda solo per cambiarlo.', example: 'Sentiero del Monte Arcosu')]
    #[BodyParameter('from', description: 'Campo 29 del format — *Origine (da)*. Toponimo o luogo di riferimento del punto di inizio.', example: 'Case Marganai')]
    #[BodyParameter('to', description: 'Campo 30 del format — *Destinazione (a)*. Toponimo o luogo di riferimento del punto di fine.', example: 'Punta Perda Liana')]
    #[BodyParameter('intermediate_stop', description: 'Campo 31 del format — *Eventuale Meta intermedia*.')]
    #[BodyParameter('distance', description: 'Campo 32 del format — *Lunghezza (m)*. **In chilometri, con un decimale** (il format chiede metri). Da 0 a 1000.', example: 12.5)]
    #[BodyParameter('ascent', description: 'Campo 33 del format — *Dislivello (m)*. In metri, come nel format. Da 0 a 20000.', example: 640)]
    #[BodyParameter('duration_forward', description: 'Campo 34 del format — *T. percorrenza (A)*. **In minuti interi** (il format chiede `ORE:MINUTI`): 4 ore sono `240`. Da 0 a 10000.', example: 240)]
    #[BodyParameter('duration_backward', description: 'Campo 35 del format — *T. percorrenza (R)*. **In minuti interi** (il format chiede `ORE:MINUTI`). Da 0 a 10000.', example: 200)]
    #[BodyParameter('activities', description: 'Campo 36 del format — *Percorribilità e livello/i di difficoltà proposti*. Lista di attività, almeno una: `trekking`, `hiking`, `mtb`, `horse`, `accessible-trails`, `nordic-walking`, `educational-trails`. Le tre voci che il format elenca si scrivono qui così: «trek» è `trekking`, «MTB» è `mtb`, «Ippo» è `horse`. Le altre sono attività che il Catasto gestisce e il format non prevede: sono accettate. Stringa JSON o forma nativa dei form.', example: ['hiking', 'mtb'])]
    #[BodyParameter('difficulty', description: 'Campo 37 del format — *Classificazione livello/i difficoltà proposti*. Facoltativo. Oggetto `attività → livello`, non una lista, per le sole attività indicate in `activities`: `{"hiking":"EE","horse":"easy"}`. I livelli ammessi dipendono dall\'attività: `trekking` e `hiking` → `T`, `E`, `EE`, `EEA` (la scala regionale mutuata dal Club Alpino Italiano, che il format prescrive); `mtb` → `TC`, `MC`, `BC`, `OC`, `EC`; `accessible-trails` → `AT`, `AE`, `AEE`; `horse`, `nordic-walking`, `educational-trails` → `easy`, `medium`, `hard`. Un livello di un\'altra scala è rifiutato con `422`. `trekking` e `hiking` hanno la stessa scala, ma non per questo lo stesso livello: dichiarandoli entrambi si può indicare un livello diverso per ciascuno. Stringa JSON o forma nativa dei form (`difficulty[hiking]=EE`).', type: 'object', example: ['hiking' => 'EE', 'horse' => 'easy'])]
    #[BodyParameter('primary_activity', description: 'L\'attività primaria del sentiero: quella per cui il sentiero è soprattutto percorso. **Obbligatorio.** Non ha un numero nel format. Uno fra: `trekking`, `hiking`, `mtb`, `horse`, `accessible-trails`, `nordic-walking`, `educational-trails`, **e deve essere una delle attività dichiarate in `activities`**: con `activities=["hiking","mtb"]` vale `hiking` o `mtb`, non `horse`. Se manca, se non è uno dei sette valori o se non è fra le attività dichiarate la creazione risponde `422` indicando `primary_activity`. Forestas può confermarla o cambiarla in istruttoria: la rilettura riporta sia la proposta sia la decisione (`submitted_data` e `approved_data`).', example: 'hiking')]
    #[BodyParameter('existing_signage', description: 'Campo 38 del format — *Stato segnaletica pre-esistente*. Uno fra: `good_condition` (c\'è ed è in buono stato), `dilapidated` (c\'è ed è fatiscente), `absent` (non c\'è). Un altro valore è rifiutato con `422`.', example: 'good_condition')]
    #[BodyParameter('overlapping_routes', description: 'Campo 39 del format — *Itinerari sovrapposti (anche solo in parte)*.')]
    #[BodyParameter('themes', description: 'Campo 45 del format — *Tematismo*. Lista di identifier, **facoltativa**: zero, uno o più. Si manda l\'identifier, mai l\'etichetta. Uno fra: `history-archaeology`, `mining-geology`, `transhumance`, `spirituality`, `woods-forests`, `flora-fauna`, `waters`, `sea-coast`, `villages`, `rural-landscape`; un altro valore è rifiutato con `422`. Stringa JSON o forma nativa dei form.', example: ['spirituality', 'waters'])]
    #[BodyParameter('managing_subjects', description: 'Campo 47 del format — *Soggetto gestore*, nella forma nuova: non più una categoria ma i **soggetti gestori** veri, **almeno uno**. Ogni soggetto è un oggetto con `name` (la denominazione), `email` e `subject`, che vale `organization` per un ente o `person` per una persona fisica. La `email` è obbligatoria e deve essere un indirizzo valido quando `subject` è `organization`; quando è `person` può mancare o valere `null`. Se un soggetto è rifiutato, l\'errore indica il ruolo e la posizione nella lista, **contata da zero** (`managing_subjects.0.email`). Stringa JSON o forma nativa dei form.', example: [['name' => 'Comune di Domusnovas', 'email' => 'protocollo@comune.example.it', 'subject' => 'organization']])]
    #[BodyParameter('surveying_subjects', description: 'Campo 49 del format — *Soggetto rilevatore*, nella forma nuova: i **soggetti rilevatori** veri, **almeno uno**. Ogni soggetto è un oggetto con `name` (la denominazione), `email` e `subject`, che vale `organization` per un ente o `person` per una persona fisica. La `email` è obbligatoria e deve essere un indirizzo valido quando `subject` è `organization`; quando è `person` può mancare o valere `null`. Se un soggetto è rifiutato, l\'errore indica il ruolo e la posizione nella lista, **contata da zero** (`surveying_subjects.0.email`). Stringa JSON o forma nativa dei form.', example: [['name' => 'Mario Rossi', 'subject' => 'person']])]
    #[BodyParameter('maintaining_subjects', description: 'Campo 53 del format — *Soggetto manutentore*, nella forma nuova: i **soggetti manutentori** veri, **almeno uno**. Il codice fiscale del manutentore non viene più richiesto. Ogni soggetto è un oggetto con `name` (la denominazione), `email` e `subject`, che vale `organization` per un ente o `person` per una persona fisica. La `email` è obbligatoria e deve essere un indirizzo valido quando `subject` è `organization`; quando è `person` può mancare o valere `null`. Se un soggetto è rifiutato, l\'errore indica il ruolo e la posizione nella lista, **contata da zero** (`maintaining_subjects.0.email`). Stringa JSON o forma nativa dei form.', example: [['name' => 'Comune di Domusnovas', 'email' => 'protocollo@comune.example.it', 'subject' => 'organization']])]
    #[BodyParameter('keeping_subjects', description: 'Campo 58 del format — *Soggetto custode*, nella forma nuova: i **soggetti custodi** veri. **Facoltativo**: può essere una lista vuota o non essere passato affatto. Ogni soggetto è un oggetto con `name` (la denominazione), `email` e `subject`, che vale `organization` per un ente o `person` per una persona fisica. La `email` è obbligatoria e deve essere un indirizzo valido quando `subject` è `organization`; quando è `person` può mancare o valere `null`. Se un soggetto è rifiutato, l\'errore indica il ruolo e la posizione nella lista, **contata da zero** (`keeping_subjects.0.email`). Stringa JSON o forma nativa dei form.', example: [])]
    #[BodyParameter('last_survey_date', description: 'Campo 64 del format — *Data ultima verifica tracciato*. Data nel formato `AAAA-MM-GG`, non futura.', example: '2026-10-20')]
    #[BodyParameter('maintenance_frequency_months', description: 'Campo 65 del format — *Frequenza manutenzione (Numero di mesi)*. Intero da 1 a 120.', example: 12)]
    #[BodyParameter('geometry', description: 'Il tracciato nuovo: un file GPX (traccia o rotta) o GeoJSON con almeno una linea di due punti, **al massimo 5 MB**. Facoltativo: si manda solo per sostituirlo. Campo 68 del format — *Traccia GPS/cartografia digitale*.')]
    #[Response(status: 404, description: 'Domanda non trovata: l\'identificatore non esiste, oppure la domanda non è nata da questo canale. I due casi ricevono la stessa risposta.')]
    #[Response(status: 409, description: 'La domanda è già stata trasmessa e non accetta più modifiche.', type: 'array{error: string}')]
    #[Response(status: 422, description: 'Modulo non valido (errori per campo), tracciato illeggibile o fuori da ogni settore del catasto, oppure settore senza numeri liberi. Nessun campo della chiamata viene scritto.', type: 'array{error: string}|array{message: string, errors: array<string, string[]>}')]
    public function __invoke(PatchApplicationRequest $request, TrailApplication $application): JsonResponse
    {
        // La domanda nata d'ufficio e' gia' stata respinta come inesistente
        // da `authorize()`, prima della validazione.
        $application = $this->configuredApplication($application);

        $file = $request->file('geometry');
        $wkt = null;

        if ($file !== null) {
            try {
                $wkt = $this->geometryReader->wktFrom((string) file_get_contents($file->getRealPath()));
            } catch (InvalidTrailGeometryException $e) {
                return $this->failure(match ($e->reason) {
                    InvalidTrailGeometryException::INVALID_XML => __('The GPX file is not valid XML'),
                    InvalidTrailGeometryException::INVALID_JSON => __('The GeoJSON file is not valid JSON'),
                    InvalidTrailGeometryException::NO_USABLE_LINE => __('The file contains no line with at least two points: a file with only points of interest, or a segment of a single point, does not describe a trail'),
                    default => __('The file is neither a GPX nor a GeoJSON: upload the track in one of the two formats'),
                }, 422);
            }
        }

        $stored = null;

        try {
            [$outcome, $computed] = DB::transaction(function () use ($request, $application, $file, $wkt, &$stored) {
                // Lo stato si rilegge sotto lock: il canale fa piu' chiamate
                // ravvicinate, e fra un controllo fatto prima e la scrittura
                // la domanda potrebbe essere stata trasmessa. E' lo stesso
                // lock di `confirmCompilation()`.
                $locked = $application->newQuery()->whereKey($application->getKey())->lockForUpdate()->first();

                if ($locked === null) {
                    throw (new ModelNotFoundException)->setModel(TrailApplication::class, [$application->getKey()]);
                }

                if ($locked->status !== TrailApplicationStatus::UnderCompilation) {
                    return ['closed', false];
                }

                $computed = false;

                if ($wkt !== null) {
                    $computed = $this->replaceGeometry($application, $file, $wkt, $stored);
                }

                if ($request->has('name')) {
                    $this->writeName($application, (string) $request->input('name'));
                }

                // La versione dello schema va per ultima: vince su un campo
                // omonimo mandato dal richiedente, perche' la stabilisce chi
                // accetta, non chi consegna.
                $application->mergeApiData(
                    array_merge(
                        $request->bodyFields(),
                        [ApplicationSchema::VERSION_KEY => app(ApplicationSchema::class)->version()],
                    ),
                    $request->deletedFields(),
                );

                return ['patched', $computed];
            });
        } catch (SectorNotFoundException) {
            $this->discardStoredFile($stored);

            return $this->failure(__('The track does not fall in any sector of the registry'), 422);
        } catch (SectorExhaustedException) {
            $this->discardStoredFile($stored);

            return $this->failure(__('The sector the track falls in has no free numbers left'), 422);
        } catch (Throwable $e) {
            $this->discardStoredFile($stored);

            throw $e;
        }

        if ($outcome === 'closed') {
            return $this->failure(__('The application has already been submitted and no longer accepts changes'), 409);
        }

        // Il tracciato nuovo ha azzerato i dati tecnici: se il servizio non ha
        // risposto in tempo, il ricalcolo va rimesso in coda a mano. Qui non
        // c'e' l'hook `created` del modello, che alla creazione prende il lock
        // e accoda il job da solo.
        if ($wkt !== null && ! $computed) {
            try {
                $application->acquireDemLock();
                $application->dispatchDem();
            } catch (Throwable $e) {
                Log::warning('trail_registry: ricalcolo DEM non riaccodato dopo la modifica del tracciato', [
                    'application_id' => $application->getKey(),
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        /**
         * Domanda aggiornata, ancora in compilazione.
         *
         * @status 200
         */
        return response()->json([
            /**
             * L'identificatore della domanda, lo stesso di prima.
             *
             * @var int
             */
            'id' => $application->getKey(),
            /**
             * `computed` se i dati tecnici sono pronti, `processing` se il calcolo è ancora in
             * corso: con un tracciato nuovo il calcolo riparte, e può non finire entro la risposta.
             *
             * @var string
             */
            'dem_status' => $application->fresh()->needsDem() ? 'processing' : 'computed',
        ]);
    }

    /**
     * Sostituisce il tracciato e rimette in riga tutto cio' che ne dipende.
     *
     * @return bool se i dati tecnici sono gia' stati calcolati
     */
    protected function replaceGeometry(TrailApplication $application, $file, string $wkt, &$stored): bool
    {
        // La geometria passa da SQL, mai da Eloquent, che la corromperebbe.
        // Nello stesso UPDATE si azzerano i dati tecnici: `needsDem()` e' falso
        // finche' `dem_data` c'e' e la geometria ha quote non nulle, quindi con
        // un GPX che porta gia' le `<ele>` il ricalcolo di ripiego si
        // tirerebbe indietro e la lettura dichiarerebbe `computed` numeri che
        // appartengono al tracciato di prima. Azzerandoli, `needsDem()` e'
        // vero per costruzione.
        $application->getConnection()->update(
            sprintf(
                "UPDATE %s SET geometry = ST_GeomFromText(?, 4326)::geography,
                 properties = CASE
                     WHEN properties IS NULL OR jsonb_typeof(properties) != 'object' THEN '{}'::jsonb
                     ELSE properties - 'dem_data'
                 END
                 WHERE id = ?",
                $application->getTable(),
            ),
            [$wkt, $application->getKey()],
        );

        // Il calcolo legge la geometria dal modello: senza rileggerlo
        // userebbe quella di prima.
        $application->refresh();

        $this->reviewReservedNumber($application, $wkt);

        // Il file consegnato si conserva tale e quale, ed e' quello che
        // l'approvazione copia sul sentiero: lasciare qui il vecchio
        // manderebbe in istruttoria due tracciati diversi. La collection
        // tiene un file solo, quindi il nuovo sostituisce.
        $stored = $application->addMedia($file->getRealPath())
            ->preservingOriginal()
            ->usingFileName($file->getClientOriginalName())
            ->toMediaCollection(TrailApplication::ORIGINAL_GEOMETRY_COLLECTION);

        return $this->demService->computeAndStore($application);
    }

    /**
     * Il numero del catasto si deriva dalla geometria: un tracciato nuovo puo'
     * cadere in un altro settore, e allora il numero riservato non vale piu'.
     *
     * Si libera e se ne riserva uno nuovo — non si usa `replaceNumber()`, che
     * copia il settore dal codice vecchio ed e' il gesto dell'operatore in
     * Nova. Il legame non si rompe: `codes()` e' una `HasMany`, quindi la
     * sostituzione aggiunge una riga alla storia dei codici.
     *
     * Se il settore non cambia il numero si tiene: e' valido, ed e' gia' stato
     * tolto dalla disponibilita'.
     */
    protected function reviewReservedNumber(TrailApplication $application, string $wkt): void
    {
        $code = $application->activeCode;

        if ($code === null) {
            return;
        }

        $sector = $this->registry->resolveSector($wkt);

        // `fullCode` del codice e' l'ambito in cui il numero e' unico:
        // regione, provincia, area e settore messi in fila. Il settore lo
        // dichiara nella propria chiave `full_code`.
        if ((string) ($sector->properties['full_code'] ?? '') === $code->fullCode) {
            return;
        }

        $this->registry->release($code, 'geometry_replaced');
        $application->unsetRelation('activeCode');
        $this->registry->reserve($application);
    }

    /**
     * Il nome passa da SQL come il payload: e' una consegna dell'API, non una
     * modifica dell'operatore, e toccare `updated_at` farebbe rifiutare con
     * 409 il salvataggio di chi ha aperto il form in Nova un attimo prima.
     */
    protected function writeName(TrailApplication $application, string $name): void
    {
        $application->getConnection()->update(
            sprintf('UPDATE %s SET name = ? WHERE id = ?', $application->getTable()),
            [$name, $application->getKey()],
        );

        $application->setAttribute('name', $name);
        $application->syncOriginalAttribute('name');
    }

    protected function discardStoredFile($stored): void
    {
        if ($stored === null) {
            return;
        }

        try {
            $stored->delete();
        } catch (Throwable $e) {
            Log::warning('trail_registry: file della modifica non rimosso dopo il rollback', [
                'media_id' => $stored->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function failure(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}
