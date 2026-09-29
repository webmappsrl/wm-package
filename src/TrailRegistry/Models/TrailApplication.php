<?php

namespace Wm\WmPackage\TrailRegistry\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Wm\WmPackage\Models\Abstracts\MultiLineString;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Database\Factories\TrailApplicationFactory;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Jobs\UpdateTrailApplicationDemJob;
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
 * codice riservato.
 *
 * Alla creazione accoda il calcolo del DEM (oc:8571): vedi il docblock di
 * `booted()`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $source
 * @property TrailApplicationStatus $status
 * @property string|null $name
 * @property-read User $user
 * @property-read Collection<int, TrailRegistryCode> $codes
 * @property-read TrailRegistryCode|null $activeCode
 */
class TrailApplication extends MultiLineString
{
    /**
     * Il file caricato dall'utente, conservato tale e quale: la geometria in
     * colonna riceve la quota del nostro DEM, e questo resta il riferimento
     * di cio' che e' stato dichiarato.
     */
    public const ORIGINAL_GEOMETRY_COLLECTION = 'original_geometry';

    protected $fillable = [
        'user_id',
        'source',
        'status',
        'name',
        'geometry',
        'properties',
    ];

    protected $casts = [
        'properties' => 'array',
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

        $this->addMediaCollection(self::ORIGINAL_GEOMETRY_COLLECTION)->singleFile();
    }

    public function dispatchDem(): void
    {
        UpdateTrailApplicationDemJob::dispatch($this->id);
    }

    /**
     * Il codice di cui mostrare la mappa: quello attivo, oppure, per
     * un'istanza rifiutata, l'ultimo che ha avuto.
     */
    public function mapCode(): ?TrailRegistryCode
    {
        return $this->activeCode ?? $this->codes()->latest('id')->first();
    }

    /**
     * La mappa del codice, la stessa della scheda nel registro: settore,
     * vicini, traccia dell'istanza e, se approvata, il sentiero. Chi cambia
     * TrailRegistryCode::getFeatureCollectionMap() cambia anche questa.
     */
    public function getFeatureCollectionMap(): array
    {
        return $this->mapCode()?->getFeatureCollectionMap() ?? parent::getFeatureCollectionMap();
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
