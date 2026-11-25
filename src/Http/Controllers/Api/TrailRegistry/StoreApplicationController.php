<?php

namespace Wm\WmPackage\Http\Controllers\Api\TrailRegistry;

use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;
use Wm\WmPackage\Http\Requests\TrailRegistry\StoreApplicationRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailGeometryException;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorExhaustedException;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorNotFoundException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;
use Wm\WmPackage\TrailRegistry\Services\ApplicationDemService;
use Wm\WmPackage\TrailRegistry\TrailGeometryReader;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * Creazione di una domanda di accatastamento di un sentiero.
 */
#[Group(
    name: 'Applications',
    description: 'Creazione e compilazione delle domande di accatastamento.',
    weight: 3,
)]
class StoreApplicationController extends Controller
{
    public function __construct(
        protected TrailGeometryReader $geometryReader,
        protected TrailRegistryService $registry,
        protected ApplicationDemService $demService,
    ) {}

    /**
     * Crea la domanda
     *
     * Apre una domanda di accatastamento in compilazione, dal modulo e dal
     * file del tracciato inviati insieme. La risposta porta l'`id` con cui si
     * caricano gli allegati e si conferma la domanda.
     *
     * «Campo N del format» rinvia alla colonna «N. campo» del foglio
     * «Format istanza accatastamento V» (il collegamento è nell'introduzione
     * di questa documentazione); accanto al numero c'è la denominazione che il
     * campo ha nel foglio.
     *
     * ### Questa chiamata non si corregge, si rifà
     *
     * Non esiste un endpoint che modifichi i dati o il tracciato di una
     * domanda già creata, ed è una scelta. Se si scopre un errore prima della
     * conferma, si crea una nuova domanda con i dati giusti e si prosegue su
     * quella: la domanda sbagliata non va integrata né confermata.
     *
     * Per lo stesso motivo la chiamata non è idempotente: due chiamate
     * identiche creano due domande, ciascuna con il proprio `id`. Non c'è un
     * endpoint che elenchi o cerchi le domande, quindi se una chiamata non ha
     * risposta (timeout di rete) non si può sapere dal canale se la domanda
     * sia nata.
     *
     * ### Corpo della richiesta
     *
     * È `multipart/form-data`, perché porta il file del tracciato (`geometry`,
     * GPX o GeoJSON) insieme ai dati. In questa forma ogni valore viaggia come
     * stringa, e i campi strutturati si possono mandare in due modi
     * equivalenti:
     *
     * - **liste e mappe** come stringa JSON (`activities` =
     *   `["hiking","mtb"]`, `difficulty` = `{"hiking":"EE"}`), oppure nella
     *   forma nativa dei form (`activities[]=hiking`, `activities[]=mtb`,
     *   `difficulty[hiking]=EE`);
     * - **booleani** come `true` o `false`, oppure `1` o `0`. Una stringa vuota
     *   non è un `false`: è un campo non compilato.
     *
     * Un JSON malformato non è un errore a sé: il campo resta una stringa e la
     * validazione risponde `422` indicando il campo. Una chiave che il format
     * non prevede non viene rifiutata: si conserva così com'è, senza
     * validarla.
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
     * ### Esempio
     *
     * `$BASE_URL` è l'URL di base del canale, indicato nell'introduzione. Le
     * due forme dei campi strutturati sono equivalenti: la seconda riga di
     * `-F` mostra la forma nativa, da usare al posto della prima.
     *
     * ```
     * curl -X POST "$BASE_URL/trail-applications" \
     *   -H "Authorization: Bearer $TOKEN" \
     *   -H "Accept: application/json" \
     *   -F "name=Sentiero del Monte Arcosu" \
     *   -F "geometry=@traccia.gpx" \
     *   -F "from=Case Marganai" \
     *   -F "to=Punta Perda Liana" \
     *   -F "distance=12.5" \
     *   -F "ascent=640" \
     *   -F "duration_forward=240" \
     *   -F "duration_backward=200" \
     *   -F 'activities=["hiking","mtb"]' \
     *   -F 'difficulty={"hiking":"EE"}' \
     *   -F "primary_activity=hiking" \
     *   -F "existing_signage=good_condition" \
     *   -F 'managing_subjects=[{"name":"Comune di Domusnovas","email":"protocollo@comune.example.it","subject":"organization"}]' \
     *   -F 'surveying_subjects=[{"name":"Mario Rossi","subject":"person"}]' \
     *   -F 'maintaining_subjects=[{"name":"Forestas","email":"protocollo@forestas.example.it","subject":"organization"}]' \
     *   -F "last_survey_date=2026-10-20" \
     *   -F "maintenance_frequency_months=12"
     *
     * # forma nativa dei campi strutturati, al posto delle righe
     * # activities e difficulty qui sopra:
     * #   -F "activities[]=hiking" -F "activities[]=mtb" -F "difficulty[hiking]=EE"
     * ```
     *
     * ### Dopo la creazione
     *
     * La domanda nasce anche se il calcolo dei dati tecnici non riesce in
     * tempo: in quel caso `dem_status` è `processing`, `dem_data` è `null` e
     * i dati tecnici vengono calcolati in seguito. Non si deve ripetere la
     * chiamata. Il numero del sentiero non è nella risposta: viene comunicato
     * quando la domanda è accettata.
     *
     * Quando `dem_data` è presente porta i dati calcolati dal tracciato, non
     * quelli dichiarati, con le stesse unità del payload: `distance` in
     * chilometri, dislivelli e quote in metri, `duration_forward` e
     * `duration_backward` in minuti interi.
     */
    #[BodyParameter('name', description: 'Nome con cui la domanda è identificata. Non è uno dei campi 29-65 del format.', example: 'Sentiero del Monte Arcosu')]
    #[BodyParameter('geometry', description: 'Il tracciato: un file GPX (traccia o rotta) o GeoJSON con almeno una linea di due punti, **al massimo 5 MB**. Campo 68 del format — *Traccia GPS/cartografia digitale*. Viaggia in questa chiamata e non si ricarica con quella degli allegati.')]
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
    #[Response(status: 422, description: 'Modulo non valido (errori per campo), tracciato illeggibile o fuori da ogni settore del catasto, oppure settore senza numeri liberi.', type: 'array{error: string}|array{message: string, errors: array<string, string[]>}')]
    public function __invoke(StoreApplicationRequest $request): JsonResponse
    {
        $file = $request->file('geometry');

        try {
            $wkt = $this->geometryReader->wktFrom((string) file_get_contents($file->getRealPath()));
        } catch (InvalidTrailGeometryException $e) {
            // Il testo dell'eccezione e' scritto per l'operatore e non e'
            // tradotto: alla richiesta si risponde con un messaggio proprio,
            // uno per ciascun motivo, perche' chi integra deve capire cosa
            // correggere senza scriverci.
            return $this->unprocessable(match ($e->reason) {
                InvalidTrailGeometryException::INVALID_XML => __('The GPX file is not valid XML'),
                InvalidTrailGeometryException::INVALID_JSON => __('The GeoJSON file is not valid JSON'),
                InvalidTrailGeometryException::NO_USABLE_LINE => __('The file contains no line with at least two points: a file with only points of interest, or a segment of a single point, does not describe a trail'),
                default => __('The file is neither a GPX nor a GeoJSON: upload the track in one of the two formats'),
            });
        }

        // Riga, payload, codice, file e dati tecnici sono scritture su strade
        // diverse: una domanda lasciata a meta' non si potrebbe correggere (lo
        // sportello non ha un endpoint di modifica del tracciato) ne'
        // confermare. O c'e' tutto, o non c'e' niente.
        //
        // Il file su disco non partecipa al rollback: se la transazione fallisce
        // dopo che e' stato salvato, la riga del media sparisce ma il file
        // resterebbe, e nessuno lo ritroverebbe (gli id non si riusano). Per
        // questo si tiene il media in `$stored` e lo si toglie nel catch.
        $stored = null;

        try {
            [$application, $computed] = DB::transaction(function () use ($request, $file, $wkt, &$stored) {
                // La classe che lo shard ha configurato, non la base: e' quella su
                // cui gira il suo `booted()` e sotto cui Nova cerca gli allegati.
                $application = TrailRegistryClasses::application()::create([
                    'user_id' => $request->user()->getKey(),
                    'source' => 'api',
                    'status' => TrailApplicationStatus::UnderCompilation,
                    'name' => $request->input('name'),
                    'properties' => [],
                ]);

                // La geometria passa da SQL, mai da Eloquent: salvarla dal
                // modello la corromperebbe. Il WKT e' legato come parametro.
                $application->getConnection()->update(
                    sprintf('UPDATE %s SET geometry = ST_GeomFromText(?, 4326)::geography WHERE id = ?', $application->getTable()),
                    [$wkt, $application->getKey()],
                );

                // Il modello in memoria non ha la geometria appena scritta, e
                // il calcolo dei dati tecnici la legge dal modello
                // (`getGeojson()` risponde null senza). Si rilegge qui, prima
                // di aver scritto altro sul modello: e' una lettura, la
                // geometria non viene mai risalvata.
                $application->refresh();

                // La riserva fa parte della creazione, come nell'interfaccia
                // di amministrazione: un tracciato fuori da ogni settore
                // solleva qui e la transazione non lascia nulla dietro.
                $this->registry->reserve($application);

                // La versione dello schema va per ultima: vince su un campo
                // omonimo mandato dal richiedente, perche' la stabilisce chi
                // accetta, non chi consegna.
                $application->writeApiData(array_merge(
                    $request->apiData(),
                    [ApplicationSchema::VERSION_KEY => app(ApplicationSchema::class)->version()],
                ));

                $stored = $application->addMedia($file->getRealPath())
                    ->preservingOriginal()
                    ->usingFileName($file->getClientOriginalName())
                    ->toMediaCollection(TrailApplication::ORIGINAL_GEOMETRY_COLLECTION);

                // Ultimo passo. Non solleva mai: se il servizio DEM non
                // risponde, o se un errore SQL interrompe il calcolo, il
                // service restituisce false e la domanda nasce lo stesso.
                // Il suo savepoint copre l'intero calcolo (lettura della
                // geometria, chiamata, scrittura), quindi un errore SQL
                // non lascia abortita questa transazione.
                return [$application, $this->demService->computeAndStore($application)];
            });
        } catch (SectorNotFoundException) {
            return $this->unprocessable(__('The track does not fall in any sector of the registry'));
        } catch (SectorExhaustedException) {
            return $this->unprocessable(__('The sector the track falls in has no free numbers left'));
        } catch (Throwable $e) {
            $this->discardStoredFile($stored);

            throw $e;
        }

        // Da qui la domanda e' committata: niente di cio' che segue deve
        // poter sollevare, o il client, vedendo un 500, ritenterebbe e
        // creerebbe una seconda domanda (con un secondo numero riservato).
        if (! $computed) {
            // Il modello ha preso il lock del ricalcolo alla creazione: senza
            // toglierlo, nessun percorso riaccoderebbe il calcolo per un'ora.
            // Il lock e' su Redis: se e' irraggiungibile il lock scade da
            // solo, e' un fastidio di un'ora, non un motivo per fallire.
            try {
                $application->releaseDemLock();
            } catch (Throwable $e) {
                Log::warning('trail_registry: lock del ricalcolo DEM non rilasciato', [
                    'application_id' => $application->getKey(),
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $demData = $computed ? $this->storedDemData($application) : null;

        // Il numero del sentiero e' gia' riservato ma non esce da qui: lo si
        // comunica solo quando la domanda e' accettata.

        /**
         * Domanda creata, in compilazione. `dem_status` dice se i dati tecnici sono già calcolati.
         *
         * @status 201
         */
        return response()->json([
            /**
             * L'identificatore della domanda: si usa per caricare gli allegati, confermare la
             * compilazione e leggere lo stato.
             *
             * @var int
             */
            'id' => $application->getKey(),
            /**
             * I dati tecnici calcolati dal tracciato: `distance` in chilometri, dislivelli e quote in
             * metri, `duration_forward` e `duration_backward` in minuti interi. **È `null` quando il
             * calcolo non è finito in tempo, e non è un errore**: la domanda è creata lo stesso, e i
             * dati si rileggono con la chiamata di lettura dello stato. Non va ripetuta la creazione.
             *
             * @var array<string, mixed>|null
             */
            'dem_data' => $demData,
            /**
             * Se i dati tecnici sono già qui. `computed`: sono in `dem_data`. `processing`: il calcolo
             * è in coda, `dem_data` è `null`, e i dati si leggono più tardi dalla chiamata di lettura
             * dello stato.
             *
             * @var 'computed'|'processing'
             */
            'dem_status' => $computed ? 'computed' : 'processing',
        ], 201);
    }

    /**
     * I dati tecnici appena scritti dal calcolo. Si rileggono dal database
     * perche' il calcolo scrive in SQL e il modello in memoria non li ha.
     *
     * Si chiama dopo il commit, dove una lettura che fallisce non puo' piu'
     * annullare la domanda: in quel caso si risponde con i dati vuoti e si
     * logga, perche' il client non deve ritentare una domanda che esiste.
     *
     * @return array<string, mixed>
     */
    protected function storedDemData(TrailApplication $application): array
    {
        try {
            $properties = $application->getConnection()->table($application->getTable())
                ->where($application->getKeyName(), $application->getKey())
                ->value('properties');

            return (array) (json_decode((string) $properties, true)['dem_data'] ?? []);
        } catch (Throwable $e) {
            Log::warning('trail_registry: dati tecnici calcolati non riletti', [
                'application_id' => $application->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Toglie dal disco il file salvato da una creazione andata in rollback.
     *
     * Lo toglie solo se la riga del media non esiste piu'. `DB::transaction()`
     * esegue i callback `afterCommit` (fra questi l'accodamento del job del
     * calcolo) dopo il COMMIT vero: se uno di essi solleva, l'eccezione arriva
     * qui con la domanda gia' committata, e cancellare il file lascerebbe una
     * riga del media che punta al nulla su una pratica valida. La lettura e'
     * dopo il rollback: se la transazione e' fallita la riga non c'e', se il
     * commit era riuscito c'e' e il file non si tocca. Se la lettura stessa
     * fallisce non si sa, e si lascia il file: un file orfano e' meno grave di
     * un file tolto a una domanda esistente.
     *
     * Non solleva: chi chiama sta gia' propagando l'errore vero.
     */
    protected function discardStoredFile(?Media $media): void
    {
        if ($media === null) {
            return;
        }

        try {
            if ($media->newQuery()->whereKey($media->getKey())->exists()) {
                return;
            }

            app(Filesystem::class)->removeAllFiles($media);
        } catch (Throwable $e) {
            Log::warning('trail_registry: file della domanda annullata non rimosso', [
                'media_id' => $media->getKey(),
                'disk' => $media->disk,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function unprocessable(string $message): JsonResponse
    {
        return response()->json(['error' => $message], 422);
    }
}
