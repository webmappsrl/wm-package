<?php

declare(strict_types=1);

namespace Wm\WmPackage\Http\Resources;

use LogicException;
use Wm\WmPackage\Enums\WmfeTrackVersion;
use Wm\WmPackage\Http\Resources\TrackVersions\LegacyTrackPropertiesTransformation;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\Traits\HasDemClassification;
use Wm\WmPackage\Services\GeoJsonService;
use Wm\WmPackage\Services\GeometryComputationService;

/**
 * Il punto unico che da una traccia produce le `properties` di ogni versione del file su `wmfe`
 * (oc:8997).
 *
 * **Il calcolo dei dati si condivide**: DEM, geometria, tassonomie, POI correlati e media si
 * prendono dal modello una volta sola, nel costruttore, e non si rifanno per ogni versione.
 *
 * **La disposizione no.** La forma corrente (`current()`) si costruisce per intero; ogni versione
 * precedente è una trasformazione applicata a ritroso, in una classe sua sotto `TrackVersions/`,
 * che parte dalla versione che la segue e rimette solo ciò che in quella è cambiato. È il metodo
 * di versionamento di Stripe, adottato in R4 dell'overview: una versione che muore si cancella
 * con la sua classe, senza che nessun'altra ne dipenda.
 *
 * Con due versioni la catena è lunga un passo e `build()` conosce quella sola trasformazione. Con
 * la terza va scritta la catena vera: applicare la trasformazione verso la versione senza numero
 * direttamente sulla forma `v2` salterebbe il passo intermedio. Finché non c'è, una versione
 * senza trasformazione fa lanciare `build()`, invece di far scrivere la forma corrente sotto il
 * path di un'altra versione.
 *
 * Chi aggiunge una versione nuova aggiunge il caso in fondo a `WmfeTrackVersion`, sposta qui la
 * costruzione della forma nuova e trasforma quella di ieri in un'altra classe di `TrackVersions/`.
 */
class EcTrackPropertiesBuilder
{
    use HasDemClassification;

    /**
     * I campi su cui vale la priorità MANUAL > OSM > DEM.
     */
    private const DEM_FIELDS = [
        'distance', 'ascent', 'descent',
        'ele_max', 'ele_min', 'ele_from', 'ele_to',
        'duration_forward', 'duration_backward',
    ];

    /**
     * Le forme delle tassonomie che la versione senza numero porta e la corrente no.
     */
    public const OLD_TAXONOMY_KEYS = ['taxonomy', 'taxonomy_where', 'taxonomyWheres'];

    /**
     * Le properties della forma corrente, calcolate una volta sola.
     *
     * @var array<string, mixed>|null
     */
    private ?array $current = null;

    /**
     * Le chiavi vecchie messe da parte, così come il modello le produce: le serve alla
     * trasformazione verso la versione senza numero, e a nessun altro.
     *
     * @var array<string, mixed>
     */
    private array $rawTaxonomies = [];

    /**
     * La geometria forzata a linestring: l'involucro del Feature la vuole calcolata in un punto
     * solo, perché fra una versione e l'altra non cambia (R6 dell'overview).
     *
     * @var array<string, mixed>|null
     */
    private ?array $geometry = null;

    public function __construct(private EcTrack $track) {}

    /**
     * La versione che `current()` costruisce.
     *
     * Dichiararla serve a non far passare inosservata la nascita di una versione nuova: un caso
     * aggiunto a `WmfeTrackVersion` senza che qui si scriva la forma nuova farebbe etichettare la
     * forma vecchia con il nome della versione nuova, e il file finirebbe sotto il suo path senza
     * nessun segnale. `build()` confronta le due e si ferma.
     */
    public static function buildsVersion(): WmfeTrackVersion
    {
        return WmfeTrackVersion::V1;
    }

    /**
     * Le `properties` nella forma corrente: è quella scritta per intero.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        if ($this->current !== null) {
            return $this->current;
        }

        $geojson = $this->track->getDetailGeojson();
        $this->geometry = json_decode(
            GeometryComputationService::make()->getModelLineMergeGeojson($this->track),
            true
        );

        $properties = GeoJsonService::make()->removeInvalidProperties($geojson['properties']);
        $properties = $this->applyDemFields($properties, $this->track);

        // Dato di servizio di TaxonomyWhereResyncService, mai pubblico: lo toglieva
        // `applyTaxonomyWhereDisplay()`, che da oc:8997 gira per la sola versione senza numero.
        // Qui vale per tutte.
        unset($properties['_taxonomy_where_backup']);

        // Le forme vecchie delle tassonomie escono dalla forma corrente e restano da parte per la
        // trasformazione che produce quella senza numero.
        foreach (self::OLD_TAXONOMY_KEYS as $key) {
            if (array_key_exists($key, $properties)) {
                $this->rawTaxonomies[$key] = $properties[$key];
                unset($properties[$key]);
            }
        }

        $properties['name'] = $this->track->getTranslations('name');
        $properties['roundtrip'] = $properties['round_trip']
            ?? GeometryComputationService::make()->isRoundtrip($this->geometry['coordinates'] ?? []);
        $properties['related_pois'] = $this->relatedPois();

        $media = $this->track->getMedia();
        if ($media->isNotEmpty()) {
            $properties['feature_image'] = new MediaResource($media->first());
            $properties['image_gallery'] = MediaResource::collection($media);
        }

        return $this->current = $properties;
    }

    /**
     * I dati grezzi che una trasformazione può servire per ricalcolare ciò che la forma corrente
     * non porta più. Oggi: le tre forme vecchie delle tassonomie, com'erano prima di uscire.
     *
     * @return array<string, mixed>
     */
    public function rawContext(): array
    {
        $this->current();

        return $this->rawTaxonomies;
    }

    /**
     * La geometria del Feature, uguale in tutte le versioni.
     *
     * @return array<string, mixed>
     */
    public function geometry(): array
    {
        $this->current();

        return $this->geometry ?? [];
    }

    /**
     * Le `properties` di ogni versione chiesta, nell'ordine in cui sono state chieste.
     *
     * @param  list<WmfeTrackVersion>  $versions
     * @return array<string, array<string, mixed>>
     */
    public function build(array $versions): array
    {
        if (self::buildsVersion() !== WmfeTrackVersion::current()) {
            throw new LogicException(sprintf(
                'La versione corrente del JSON della traccia è "%s", ma %s costruisce ancora "%s": '
                .'scrivi qui la forma nuova e trasforma quella vecchia in una classe di TrackVersions.',
                WmfeTrackVersion::current()->value,
                class_basename(self::class),
                self::buildsVersion()->value
            ));
        }

        $current = $this->current();

        $byVersion = [self::buildsVersion()->value => $current];

        // La catena all'indietro: ogni versione precedente parte da quella che la segue. Oggi il
        // passo è uno solo; con la terza versione qui va scritta la catena vera.
        if (in_array(LegacyTrackPropertiesTransformation::appliesTo(), $versions, true)) {
            if (LegacyTrackPropertiesTransformation::appliesFrom() !== self::buildsVersion()) {
                throw new LogicException(sprintf(
                    'La trasformazione verso "%s" parte da "%s", ma la forma costruita è "%s": '
                    .'manca il passo intermedio della catena.',
                    LegacyTrackPropertiesTransformation::appliesTo()->value,
                    LegacyTrackPropertiesTransformation::appliesFrom()->value,
                    self::buildsVersion()->value
                ));
            }

            $byVersion[LegacyTrackPropertiesTransformation::appliesTo()->value] = LegacyTrackPropertiesTransformation::apply(
                $current,
                $this->rawContext(),
                $this->track
            );
        }

        $requested = [];
        foreach ($versions as $version) {
            if (! isset($byVersion[$version->value])) {
                // Succederà alla v2: una versione in mezzo alla catena senza la sua
                // trasformazione. Meglio fermarsi che scrivere la forma corrente sotto il path di
                // un'altra versione, che è un file sbagliato di cui nessuno si accorge.
                throw new LogicException(sprintf(
                    'Nessuna trasformazione verso la versione "%s" del JSON della traccia: '
                    .'la forma corrente è "%s".',
                    $version->value,
                    WmfeTrackVersion::current()->value
                ));
            }

            $requested[$version->value] = $byVersion[$version->value];
        }

        return $requested;
    }

    /**
     * I POI correlati, con il fallback per i modelli senza la tabella pivot.
     */
    private function relatedPois(): array
    {
        try {
            return $this->track->ecPois
                ->whereNull('osmfeatures_id') // TODO: da rimuovere, aggiunto per osm2cai
                ->map(fn (EcPoi $ecPoi) => RelatedEcPoiResource::make($ecPoi))
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Applica la priorità MANUAL > OSM > DEM ai nove campi principali e toglie le sorgenti raw.
     */
    protected function applyDemFields(array $properties, object $model): array
    {
        foreach (self::DEM_FIELDS as $field) {
            $classified = $this->classifyField($model, $field);
            if ($classified['currentValue'] !== null) {
                $properties[$field] = $classified['currentValue'];
            } else {
                unset($properties[$field]);
            }
        }

        unset($properties['manual_data'], $properties['dem_data'], $properties['osm_data']);

        return $properties;
    }
}
