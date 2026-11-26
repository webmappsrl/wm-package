<?php

namespace Wm\WmPackage\Http\Controllers\Api\TrailRegistry;

use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\QueryException;
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
     * Apre una domanda di accatastamento in compilazione. La risposta porta
     * l'`id` con cui si completano i dati, si caricano gli allegati e si
     * conferma la domanda.
     *
     * «Campo N del format» rinvia alla colonna «N. campo» del foglio
     * «Format istanza accatastamento V» (il collegamento è nell'introduzione
     * di questa documentazione); accanto al numero c'è la denominazione che il
     * campo ha nel foglio.
     *
     * ### La domanda si apre con poco e si completa dopo
     *
     * Questa chiamata chiede tre cose: `reference`, `name` e il file del
     * tracciato. **Nessun campo del modulo è obbligatorio qui**: si possono
     * mandare subito, se si hanno, oppure consegnare più tardi con
     * `PATCH /trail-applications/{id}`, una o più volte. A pretendere il
     * modulo completo è la conferma.
     *
     * Un campo mandato, però, deve valere qualcosa di ammesso: «facoltativo»
     * significa che si può non mandarlo, non che si può mandarlo sbagliato.
     *
     * ### Una domanda per `reference`
     *
     * `reference` è il riferimento con cui la pratica è identificata presso
     * chi la consegna, e due domande non possono averne uno uguale.
     *
     * Una creazione con un `reference` già usato **non apre una seconda
     * domanda**: risponde `422` e nella risposta mette l'`id` di quella che
     * esiste già. È il modo per riprendere dopo un timeout di rete: se non si
     * sa se la prima chiamata sia arrivata, si richiama con lo stesso
     * `reference` e si ottiene l'identificatore della domanda nata, da cui si
     * prosegue con le modifiche.
     *
     * Il comportamento è quello che la bozza IETF `Idempotency-Key` descrive
     * per una risorsa che non confronta il contenuto delle richieste:
     * <https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/>.
     * Qui la chiave non è un identificatore della chiamata ma della pratica,
     * e per questo viaggia come campo del modulo e non come header.
     *
     * ### Corpo della richiesta
     *
     * È `multipart/form-data`, perché porta il file del tracciato (`geometry`,
     * GPX o GeoJSON) insieme agli altri due campi.
     *
     * **I campi del modulo non si mandano qui**: se ne arriva uno, la risposta
     * è `422` e lo nomina. Si consegnano con
     * `PATCH /trail-applications/{id}`, dove sono documentati uno per uno,
     * insieme alle unità di misura e ai valori ammessi.
     *
     * ### Esempio
     *
     * `$BASE_URL` è l'URL di base del canale, indicato nell'introduzione.
     *
     * ```
     * curl -X POST "$BASE_URL/trail-applications" \
     *   -H "Authorization: Bearer $TOKEN" \
     *   -H "Accept: application/json" \
     *   -F "reference=FORES_02/2026/000123" \
     *   -F "name=Sentiero del Monte Arcosu" \
     *   -F "geometry=@traccia.gpx"
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
    #[BodyParameter('reference', description: 'Il riferimento con cui la pratica è identificata presso chi la consegna. **Obbligatorio** e **univoco**: una creazione con un riferimento già usato non apre una seconda domanda e risponde `422` con l\'`id` di quella esistente. Non è uno dei campi 29-65 del format.', example: 'FORES_02/2026/000123')]
    #[BodyParameter('name', description: 'Nome con cui la domanda è identificata. Non è uno dei campi 29-65 del format.', example: 'Sentiero del Monte Arcosu')]
    #[BodyParameter('geometry', description: 'Il tracciato: un file GPX (traccia o rotta) o GeoJSON con almeno una linea di due punti, **al massimo 5 MB**. Campo 68 del format — *Traccia GPS/cartografia digitale*. Viaggia in questa chiamata e non si ricarica con quella degli allegati.')]
    #[Response(status: 422, description: 'Modulo non valido (errori per campo), riferimento già usato (la risposta porta l\'`id` della domanda esistente), tracciato illeggibile o fuori da ogni settore del catasto, oppure settore senza numeri liberi.', type: 'array{error: string, id?: int}|array{message: string, errors: array<string, string[]>}')]
    public function __invoke(StoreApplicationRequest $request): JsonResponse
    {
        $reference = $request->input('reference');

        // Prima di toccare il file: un riferimento gia' usato e' una domanda
        // che esiste, non un modulo sbagliato. Si risponde subito, prima di
        // leggere il tracciato e di prenotare un numero del catasto.
        if (is_string($reference) && ($existing = $this->applicationByReference($reference)) !== null) {
            return $this->referenceAlreadyUsed($existing);
        }

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
                    'reference' => $request->input('reference'),
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
        } catch (QueryException $e) {
            $this->discardStoredFile($stored);

            // La ricerca di poco fa non basta: chi ritenta dopo un timeout
            // chiama mentre la prima richiesta e' ancora dentro la sua
            // transazione, quindi non vede niente e arriva fin qui. Il
            // conflitto emerge sull'indice unico, al commit dell'altra.
            //
            // 23505 = unique_violation in PostgreSQL.
            if (($e->errorInfo[0] ?? null) === '23505'
                && is_string($reference)
                && ($existing = $this->applicationByReference($reference)) !== null) {
                return $this->referenceAlreadyUsed($existing);
            }

            throw $e;
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
             * I dati tecnici calcolati dal tracciato, campo per campo. Le lunghezze sono in
             * **chilometri** (`distance`), le quote e i dislivelli in **metri** (`ascent`,
             * `descent`, `ele_min`, `ele_max`, `ele_from`, `ele_to`) e le durate in **minuti
             * interi**.
             *
             * `duration_forward` e `duration_backward` sono le durate a piedi, ripetute anche
             * sotto `duration_forward_hiking` e `duration_backward_hiking`; le due `_bike` sono
             * le stesse tratte in bicicletta. `round_trip` dice se il tracciato torna al punto
             * di partenza.
             *
             * **Ogni campo può essere `null` da solo**: il servizio di calcolo restituisce ciò
             * che riesce a determinare, e un campo mancante non è un errore.
             *
             * **L'oggetto intero è `null` quando il calcolo non è finito in tempo, e non è un
             * errore**: la domanda è creata lo stesso, e i dati si rileggono con la chiamata di
             * lettura dello stato. **Non va ripetuta la creazione**: con lo stesso `reference`
             * risponderebbe `422`, e con uno diverso aprirebbe una seconda domanda.
             *
             * @var array{
             *     distance: float|null,
             *     ascent: int|null,
             *     descent: int|null,
             *     ele_min: int|null,
             *     ele_max: int|null,
             *     ele_from: int|null,
             *     ele_to: int|null,
             *     duration_forward: int|null,
             *     duration_backward: int|null,
             *     duration_forward_hiking: int|null,
             *     duration_backward_hiking: int|null,
             *     duration_forward_bike: int|null,
             *     duration_backward_bike: int|null,
             *     round_trip: bool|null
             * }|null
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

    /**
     * La domanda che porta gia' quel riferimento, se c'e'. Cerca fra quelle
     * del canale: una domanda d'ufficio non ha un riferimento esterno, e il
     * confronto con `null` non la troverebbe comunque.
     */
    protected function applicationByReference(string $reference): ?TrailApplication
    {
        return TrailRegistryClasses::application()::query()
            ->where('reference', $reference)
            ->first();
    }

    /**
     * La risposta a un riferimento gia' usato. Porta l'`id` della domanda che
     * esiste, e quello e' il punto: chi e' andato in timeout non sa se la sua
     * chiamata era arrivata, e da questa risposta impara tutte e due le cose
     * — che la domanda e' nata, e come si chiama — quindi riprende da li' con
     * le modifiche, senza dover cercare per un identificatore nostro che non
     * ha mai ricevuto.
     *
     * Vale qualunque tracciato porti la chiamata e in qualunque stato sia la
     * domanda trovata: in ogni caso la cosa giusta da fare e' non aprirne una
     * seconda.
     */
    protected function referenceAlreadyUsed(TrailApplication $existing): JsonResponse
    {
        return response()->json([
            'error' => __('An application with this reference already exists'),
            'id' => $existing->getKey(),
        ], 422);
    }
}
