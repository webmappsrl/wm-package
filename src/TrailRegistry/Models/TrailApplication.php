<?php

namespace Wm\WmPackage\TrailRegistry\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Wm\WmPackage\Models\Abstracts\MultiLineString;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\UgcMediaHashService;
use Wm\WmPackage\TrailRegistry\Database\Factories\TrailApplicationFactory;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Jobs\UpdateTrailApplicationDemJob;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * La domanda di accatastamento di un sentiero.
 *
 * Eredita da MultiLineString (quindi da GeometryModel) e non da EcTrack: riusa
 * geometria PostGIS ed esportazioni senza portarsi dietro indicizzazione nella
 * ricerca, observer, preferiti e rigenerazione delle mappe vettoriali, che su
 * una domanda non ancora approvata la farebbero comparire nell'app.
 *
 * Non esistono istanze non prevalidate: se il controllo formale non passa,
 * l'API rifiuta e Nova non salva. Ogni riga qui porta quindi gia' il proprio
 * codice riservato, anche quando e' `under_compilation`.
 *
 * Esistono invece istanze **incomplete**: in `under_compilation` lo sportello
 * ha creato la domanda e non ha ancora finito di consegnarla — i dati e la
 * geometria ci sono, gli allegati no, perche' viaggiano in chiamate separate.
 * I dati che arrivano dall'API vivono nella colonna `api_data`, fuori da
 * `properties`: quella finisce intera nel GeoJSON del modello, e di li' nelle
 * chiamate verso l'esterno. Si scrivono una volta sola alla creazione e non si
 * correggono: l'operatore li accetta o li respinge, non li modifica
 * (oc:8960).
 *
 * Alla creazione accoda il calcolo del DEM (oc:8571): vedi il docblock di
 * `booted()`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $source
 * @property TrailApplicationStatus $status
 * @property string|null $name
 * @property array<string, mixed>|null $api_data
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, TrailRegistryCode> $codes
 * @property-read TrailRegistryCode|null $activeCode
 */
class TrailApplication extends MultiLineString
{
    /**
     * Dichiarata e non ricavata dal nome della classe, come negli altri modelli
     * del Catasto: uno shard puo' sostituire questo modello con una sottoclasse
     * (`wm-package.features.trail_registry.models.application`), ed Eloquent,
     * senza questa riga, le farebbe cercare una tabella col nome di lei.
     */
    protected $table = 'trail_applications';

    /**
     * Il file caricato dall'utente, conservato tale e quale: la geometria in
     * colonna riceve la quota del nostro DEM, e questo resta il riferimento
     * di cio' che e' stato dichiarato.
     */
    public const ORIGINAL_GEOMETRY_COLLECTION = 'original_geometry';

    /**
     * Gli altri cinque allegati del format (campi 67, 69, 70, 71, 72), che
     * arrivano in chiamate separate dopo la creazione (oc:8960).
     */
    public const REPORT_COLLECTION = 'report';

    public const TRAIL_DATA_SHEET_COLLECTION = 'trail_data_sheet';

    public const PHOTOS_COLLECTION = 'photos';

    public const AUTHORIZATIONS_COLLECTION = 'authorizations';

    public const MAINTENANCE_PLAN_COLLECTION = 'maintenance_plan';

    /**
     * Gli allegati dell'istanza non passano sul sentiero all'approvazione:
     * sono documenti dell'istruttoria — relazione, tabella dati,
     * autorizzazioni, piano di manutenzione, fotografie del rilievo — e sul
     * sentiero pubblicato diventerebbero URL scaricabili. `Media::copy()`
     * duplica il file sul disco, quindi una copia fatta per errore non si
     * annulla scollegandola (oc:8960).
     *
     * La traccia originale non e' qui: continua a essere copiata, come prima
     * di questo lavoro.
     */
    public const COLLECTIONS_NOT_COPIED_ON_APPROVAL = [
        self::REPORT_COLLECTION,
        self::TRAIL_DATA_SHEET_COLLECTION,
        self::PHOTOS_COLLECTION,
        self::AUTHORIZATIONS_COLLECTION,
        self::MAINTENANCE_PLAN_COLLECTION,
    ];

    /**
     * Le collection che tengono un documento solo: un reinvio lo sostituisce.
     * La galleria fotografica non e' qui perche' ne contiene da una a cinque.
     */
    public const SINGLE_FILE_COLLECTIONS = [
        self::ORIGINAL_GEOMETRY_COLLECTION,
        self::REPORT_COLLECTION,
        self::TRAIL_DATA_SHEET_COLLECTION,
        self::AUTHORIZATIONS_COLLECTION,
        self::MAINTENANCE_PLAN_COLLECTION,
    ];

    protected $fillable = [
        'user_id',
        'source',
        'status',
        'name',
        'geometry',
        'properties',
        'rejection_reason',
    ];

    protected $casts = [
        'properties' => 'array',
        'api_data' => 'array',
        'status' => TrailApplicationStatus::class,
    ];

    /**
     * HasPackageFactory risolve la factory da get_called_class(): senza questo
     * override, una sottoclasse in un altro namespace romperebbe ::factory().
     * Vedi CLAUDE.md di questo repo.
     */
    protected static function newFactory(): Factory
    {
        return TrailApplicationFactory::new();
    }

    /**
     * Il calcolo DEM parte a ogni istanza nuova, dopo il commit: Nova crea
     * l'istanza e riserva il codice nella stessa transazione, e se la
     * prenotazione fallisce non deve restare in coda un job per una riga che
     * non esiste (oc:8571).
     */
    protected static function booted(): void
    {
        static::created(function (TrailApplication $application) {
            // Il lock si prende anche qui: il dettaglio che Nova apre subito
            // dopo non deve accodare un secondo job (oc:8660).
            $application->acquireDemLock();
            UpdateTrailApplicationDemJob::dispatch($application->id)->afterCommit();
        });
    }

    /**
     * Il file caricato, conservato tale e quale: la geometria riceve la quota
     * del nostro DEM, e questo resta il riferimento di cio' che e' stato
     * dichiarato.
     */
    public function registerMediaCollections(): void
    {
        parent::registerMediaCollections();

        foreach (self::SINGLE_FILE_COLLECTIONS as $collection) {
            $this->addMediaCollection($collection)->singleFile();
        }

        // La documentazione fotografica (campo 70) ne ammette da una a cinque,
        // quindi niente singleFile(): dai reinvii si difende deduplicando sul
        // contenuto con UgcMediaHashService, e il limite di cinque si conta
        // dopo la deduplicazione, altrimenti conterebbe i retry di rete invece
        // delle fotografie (oc:8960).
        $this->addMediaCollection(self::PHOTOS_COLLECTION);
    }

    /**
     * Aggiunge una fotografia del rilievo, se gia' non c'e'.
     *
     * Le fotografie arrivano in chiamate separate: se una va in timeout e il
     * client la ripete, gli stessi byte arriverebbero due volte e diventerebbero
     * due fotografie, mangiandosi il limite di cinque. Il confronto e' sul
     * contenuto, con UgcMediaHashService, come fa gia' lo store UGC per i retry
     * dell'app (oc:8718): il nome non conta, perche' chi ripete una chiamata
     * spesso lo cambia — l'app UGC manda nomi posizionali.
     *
     * L'hash finisce nei custom_properties, cosi' il confronto successivo non
     * deve riaprire il file dal disco.
     *
     * @param  UploadedFile|string  $file  il file caricato, o il suo percorso
     * @return Media la fotografia gia' presente, o quella appena aggiunta
     */
    public function addPhoto(UploadedFile|string $file): Media
    {
        $hashes = UgcMediaHashService::make();
        $hash = $hashes->hashOfFile($file instanceof UploadedFile ? $file->getRealPath() : $file);

        // La relazione va riletta: senza, una fotografia aggiunta un attimo
        // prima sulla stessa istanza non comparirebbe nel confronto, e il
        // reinvio passerebbe.
        $this->load('media');

        $presente = $hashes->findByContent($this, $hash, self::PHOTOS_COLLECTION);
        if ($presente !== null) {
            return $presente;
        }

        return $this->addMedia($file)
            ->withCustomProperties([UgcMediaHashService::HASH_PROPERTY => $hash])
            ->toMediaCollection(self::PHOTOS_COLLECTION);
    }

    /**
     * La colonna che tiene il payload dell'API, conservato com'e' arrivato.
     *
     * E' una colonna e non una chiave dentro `properties` perche'
     * GeoJsonService::getModelAsGeojson riversa `properties` per intero nella
     * Feature, e quella Feature viaggia verso il servizio DEM e verso il
     * sentiero pubblicato: e-mail, nomi, codici fiscali e partite IVA
     * uscirebbero da ogni strada che serializza l'istanza, e starebbe a chi
     * scrive quella strada ricordarsene. Fuori da `properties` non ci sono
     * strade da ricordare.
     *
     * Non e' fillable: si scrive solo con writeApiData(), una volta sola alla
     * creazione, e nessuna interfaccia lo modifica (oc:8960).
     */
    public const API_DATA_COLUMN = 'api_data';

    /**
     * I campi che l'istanza deve contenere perche' la consegna si consideri
     * conclusa, con gli identifier del format (campi 29-65).
     *
     * L'elenco non vive piu' qui: lo dichiara `ApplicationSchema`, che lo
     * shard puo' ridefinire nel container. Ci sono solo i campi obbligatori
     * incondizionatamente. Non ci sono quelli che dipendono dal valore di un
     * altro campo (`soggetto_gestore_other`, `soggetto_rilevatore_other`,
     * `soggetto_rilevatore_email`, `soggetto_manutentore_other`,
     * `soggetto_manutentore_cf`, `soggetto_custode`): la loro
     * obbligatorieta' non e' una proprieta' dell'istanza ma una regola di
     * validazione della chiamata, e fra gli obbligatori terrebbero
     * un'istanza per sempre lontana dalla conferma. Non c'e' nemmeno
     * `difficulty` (37): il format ne valida la forma ma non ne chiede la
     * presenza.
     *
     * @return list<string>
     */
    public static function requiredApiFields(): array
    {
        return app(ApplicationSchema::class)->requiredFieldNames();
    }

    /**
     * Gli allegati obbligatori. `authorizations` (campo 71) non c'e': l'esito
     * del tavolo tecnico lo dichiara facoltativo, contro la colonna formale del
     * foglio, e fra le due vale l'esito.
     *
     * @return list<string>
     */
    public static function requiredAttachments(): array
    {
        return [
            self::REPORT_COLLECTION,             // 67
            self::ORIGINAL_GEOMETRY_COLLECTION,  // 68
            self::TRAIL_DATA_SHEET_COLLECTION,   // 69
            self::PHOTOS_COLLECTION,             // 70
            self::MAINTENANCE_PLAN_COLLECTION,   // 72
        ];
    }

    /**
     * Cosa manca perche' la consegna sia completa, in forma strutturata e non
     * in prosa, cosi' che lo sportello possa mostrare l'errore accanto ai campi
     * giusti.
     *
     * @return array{fields: list<string>, attachments: list<string>}
     */
    public function missingForConfirmation(): array
    {
        $apiData = (array) ($this->api_data ?? []);

        $fields = array_values(array_filter(
            static::requiredApiFields(),
            fn (string $field) => ! isset($apiData[$field])
                || $apiData[$field] === ''
                || $apiData[$field] === [],
        ));

        $attachments = array_values(array_filter(
            static::requiredAttachments(),
            fn (string $collection) => $this->getMedia($collection)->isEmpty(),
        ));

        return ['fields' => $fields, 'attachments' => $attachments];
    }

    /**
     * Scrive il payload nella colonna `api_data`.
     *
     * Passa da SQL e non da Eloquent perche' `updated_at` non va toccato: e'
     * una consegna dell'API, non una modifica dell'operatore, e il trafficCop
     * di Nova rifiuterebbe con 409 il salvataggio di chi ha aperto il form un
     * attimo prima.
     */
    public function writeApiData(array $payload): void
    {
        $this->getConnection()->update(
            sprintf(
                'UPDATE %s SET %s = ?::jsonb WHERE id = ?',
                $this->getTable(),
                self::API_DATA_COLUMN,
            ),
            [json_encode($payload, JSON_THROW_ON_ERROR), $this->getKey()],
        );

        // Il modello in memoria resterebbe senza i dati appena scritti: chi
        // serializza l'istanza subito dopo la consegna risponderebbe con
        // api_data vuoto.
        $this->setAttribute(self::API_DATA_COLUMN, $payload);
        $this->syncOriginalAttribute(self::API_DATA_COLUMN);
    }

    public function dispatchDem(): void
    {
        UpdateTrailApplicationDemJob::dispatch($this->id);
    }

    /**
     * Il codice di cui mostrare la mappa: quello attivo, oppure, per
     * un'istanza respinta, l'ultimo che ha avuto.
     */
    public function mapCode(): ?TrailRegistryCode
    {
        return $this->activeCode ?? $this->codes()->latest('id')->first();
    }

    /**
     * La versione della mappa dell'istanza: quella del codice mostrato, piu'
     * lo stato dell'istanza — «Approva» e «Respingi» cambiano l'istanza anche
     * quando il codice non cambia id (oc:8662).
     */
    public function mapVersion(): string
    {
        return sprintf(
            '%s-%s-%d',
            $this->mapCode()?->mapVersion() ?? 'none',
            $this->status->value,
            $this->updated_at?->getTimestamp() ?? 0,
        );
    }

    /**
     * La mappa del codice, la stessa della scheda nel registro: settore,
     * vicini, traccia dell'istanza e, se approvata, il sentiero. Chi cambia
     * TrailRegistryCode::getFeatureCollectionMap() cambia anche questa.
     *
     * Dalla scheda dell'istanza numero e profilo stanno sulla traccia
     * proposta anche dopo l'approvazione: e' quella che il gestore valuta
     * (oc:8662).
     */
    public function getFeatureCollectionMap(): array
    {
        return $this->mapCode()?->getFeatureCollectionMap(TrailRegistryCode::MAP_SUBJECT_APPLICATION)
            ?? parent::getFeatureCollectionMap();
    }

    /**
     * Il nome di classe reale, non l'alias del morphMap.
     *
     * GeometryModel::getMorphClass() compone meccanicamente
     * 'App\Models\'.class_basename($this). Quell'alias ha senso per i modelli
     * storici, perche' `App\Models\EcTrack` esiste davvero nei progetti
     * consumer e nel database ci sono righe gia' salvate con quel valore: e'
     * compatibilita' con dati esistenti. `App\Models\TrailApplication` invece
     * non esiste in nessun consumer — l'istanza vive solo nel package — e
     * aggiungerlo al morphMap condiviso sarebbe una finzione imposta a tutti i
     * progetti che usano il package, catasto o no. La tabella delle istanze
     * nasce vuota, quindi non c'e' alcun dato storico da rispettare, che e' la
     * sola ragione per cui quegli alias esistono.
     *
     * Senza questo override risolvere `$media->model` solleva un
     * Error («Class "App\Models\TrailApplication" not found»): non si
     * riescono ne' a caricare gli allegati di un'istanza (MediaObserver
     * legge il modello per ricavare `app_id`) ne' a copiarli sul sentiero
     * all'approvazione.
     */
    public function getMorphClass(): string
    {
        return static::class;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<TrailRegistryCode, $this> */
    public function codes(): HasMany
    {
        return $this->hasMany(TrailRegistryClasses::code());
    }

    /**
     * Il codice attivo di questa istanza: quello riservato prima
     * dell'istruttoria, quello assegnato dopo l'approvazione.
     */
    public function activeCode(): HasOne
    {
        return $this->hasOne(TrailRegistryClasses::code())
            ->whereIn('status', array_map(
                fn (TrailCodeStatus $s) => $s->value,
                TrailCodeStatus::active(),
            ));
    }
}
