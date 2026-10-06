<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Anomalies\TrailRegistryAnomalyTypes;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\Nova\Cards\TrailRegistryNoticeCard;
use Wm\WmPackage\TrailRegistry\Nova\Fields\TrailRegistryMap;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailAnomalyTypeFilter;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * La lista di lavoro del gestore: cosa non va, su quale sentiero, e
 * possibilmente cosa scrivere per sistemarlo.
 *
 * Le anomalie non si correggono qui: il dato lo possiede la piattaforma di
 * origine, si sistema la scheda alla fonte e l'import successivo fa sparire
 * la riga da se'.
 *
 * @extends resource<\Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly>
 */
class TrailRegistryAnomaly extends Resource
{
    use HidesWhenTrailRegistryDisabled, ResolvesCanonicalResources;

    public static $model = \Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly::class;

    public static function newModel()
    {
        return static::newDomainModel(\Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly::class, TrailRegistryClasses::anomaly());
    }

    /**
     * Fissa, non derivata dal nome della classe: coerente con TrailRegistryCode
     * e TrailApplication, cosi' anche una sottoclasse dello shard resta
     * raggiungibile con la stessa chiave.
     */
    public static function uriKey()
    {
        return 'trail-registry-anomalies';
    }

    /**
     * Il titolo non e' una colonna: `type` e' un enum, e Nova lo darebbe in
     * pasto a una conversione in stringa che solleva un errore (verificato:
     * «Object of class TrailRegistryAnomalyType could not be converted to
     * string», vendor/laravel/nova/src/Resource.php:416). Si compone qui,
     * delegando a `titleFor()`.
     */
    public function title(): string
    {
        return $this->titleFor($this->resource);
    }

    /**
     * Punto di estensione per lo shard: compone il titolo dell'anomalia.
     * Il pacchetto appende ` · #<id traccia>` solo quando la traccia esiste
     * — un'anomalia senza traccia (es. un tipo dello shard che riguarda un
     * dato esterno, non una traccia) non ha nulla da appendere dopo il
     * cancelletto, e ometterlo evita un titolo tipo «tipo · #» che non dice
     * nulla in piu'. Un tipo dello shard si mostra con l'etichetta del
     * registro dei tipi; uno sconosciuto con la chiave grezza.
     */
    protected function titleFor(\Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly $anomaly): string
    {
        $type = $anomaly->type;
        $label = match (true) {
            $type instanceof TrailRegistryAnomalyType => $type->value,
            is_string($type) && $type !== '' => TrailRegistryAnomalyTypes::label($type),
            default => __('anomaly'),
        };

        if ($anomaly->ec_track_id === null) {
            return trim($label);
        }

        return trim($label.' · #'.$anomaly->ec_track_id);
    }

    /**
     * I dati dell'anomalia stanno in una colonna JSON, e la frase che la
     * descrive non esiste come colonna: si cerca dentro il contesto, che e'
     * dove vivono codici e nomi dei sentieri coinvolti.
     */
    public static $search = [];

    public static function applySearch(Builder $query, string $search): Builder
    {
        $needle = trim($search);

        if ($needle === '') {
            return $query;
        }

        return $query->whereRaw('context::text ILIKE ?', ['%'.$needle.'%']);
    }

    public static function label(): string
    {
        return __('Anomalies');
    }

    public static function singularLabel(): string
    {
        return __('Anomaly');
    }

    /**
     * Ordinamento predefinito: prima la provenienza (uno shard puo' avere le
     * sue accanto a quelle del catasto senza mischiarle), poi il tipo — le
     * anomalie dello stesso genere stanno insieme, ed e' cosi' che si
     * lavorano, una correzione per volta ripetuta su tutte le schede che ne
     * hanno bisogno — infine la traccia, con quelle senza traccia in coda:
     * non hanno un id su cui ordinare, e non sono comunque il caso comune.
     */
    public static function indexQuery(NovaRequest $request, $query)
    {
        if (empty($request->query('orderBy'))) {
            $query->getQuery()->orders = [];

            $query->orderBy('source')->orderBy('type')->orderByRaw('ec_track_id NULLS LAST');
        }

        return $query;
    }

    /**
     * Le righe le scrive solo il comando di normalizzazione, e si correggono
     * alla fonte. Una riga cancellata a mano tornerebbe alla prossima
     * esecuzione, dando l'impressione che il backoffice non funzioni.
     */
    public static function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    public function fields(NovaRequest $request): array
    {
        $trackResource = static::resourceForModel(
            EcTrack::class,
            \Wm\WmPackage\Nova\EcTrack::class,
        );

        $fields = [];

        $fields[] = $this->subjectField();

        // `type` e' l'enum del catasto o una stringa (shard/sconosciuto, vedi
        // AnomalyTypeCast): un tipo stringa non ha `->value`, quindi si passa
        // dal registro, che per un tipo sconosciuto ricade sulla stringa
        // grezza.
        $fields[] = Text::make(__('Type'), function () {
            $type = $this->type;

            return $type instanceof TrailRegistryAnomalyType
                ? $type->value
                : ($type !== null ? TrailRegistryAnomalyTypes::label($type) : null);
        })->sortable();

        // Una sola colonna, con dentro una tabellina etichetta/valore le cui
        // righe cambiano con il tipo di anomalia: vedi AnomalyDetailRenderer.
        $fields[] = Text::make(
            __('Detail'),
            fn () => AnomalyDetailRenderer::render($this->resource),
        )->asHtml();

        // Il sentiero collegato — l'assegnatario del codice, o il primo dei
        // gemelli — resta raggiungibile anche dentro il pannello, oltre che
        // dal collegamento nella descrizione.
        if ($trackResource !== null) {
            $fields[] = BelongsTo::make(__('Linked trail'), 'relatedEcTrack', $trackResource)
                ->nullable()
                ->onlyOnDetail();
        }

        $fields[] = DateTime::make(__('Detected on'), 'created_at')->sortable();

        // La mappa solo nella scheda: nell'elenco sarebbe una riga alta come
        // uno schermo per ogni anomalia. Cosa disegna lo decide il modello
        // (TrailRegistryAnomaly::getFeatureCollectionMap()), che varia le
        // feature secondo il tipo.
        $fields[] = TrailRegistryMap::make(__('Map'), 'geometry');

        $fields[] = Text::make(
            __('Legend'),
            fn () => AnomalyMapLegendRenderer::render($this->resource),
        )->asHtml()->onlyOnDetail();

        return $fields;
    }

    /**
     * Punto di estensione per lo shard: la colonna «Sentiero». Il pacchetto
     * la rende un `Text` con dentro il link composto da
     * `AnomalyDetailRenderer::trackLink()` (nome + icona verso la
     * piattaforma di origine) e non un `BelongsTo`, perche' un campo di
     * relazione non lascia spazio per appenderci l'icona. Uno shard con
     * un'altra fonte per l'anomalia (niente traccia EC) puo' sostituirla con
     * il campo che gli serve.
     *
     * Senza traccia (`ec_track_id` nullo) non c'e' nulla da linkare: si
     * mostra solo il titolo dell'anomalia, senza il cancelletto vuoto che
     * `trackLink()` produrrebbe da un id assente.
     */
    protected function subjectField(): Field
    {
        return Text::make(
            __('Trail'),
            function () {
                if ($this->resource->ec_track_id === null) {
                    return e($this->titleFor($this->resource));
                }

                return AnomalyDetailRenderer::trackLink($this->context['track'] ?? ['id' => $this->ec_track_id]);
            },
        )->asHtml();
    }

    /**
     * La spiegazione in cima all'elenco.
     *
     * Senza, questa schermata e' una tabella di righe rosse che non dice
     * perche' esiste ne' cosa ci si aspetta da chi la guarda: la regola che
     * la governa — nel registro solo codici senza dubbi, qui tutto il resto —
     * non e' deducibile dalle righe. Il testo vive qui e non nella
     * documentazione perche' e' qui che serve leggerlo.
     */
    public function cards(NovaRequest $request): array
    {
        return [
            new TrailRegistryNoticeCard($this->noticeBody()),
        ];
    }

    protected function noticeBody(): string
    {
        $intro = __('These are the trails that <strong>did not get a number</strong>, and the reason why. Only codes with no doubt pending enter the code registry: everything still unresolved is here, and while it is here that trail has no number.');

        // L'azione sta qui e non su ogni riga: e' la stessa per tutte, e
        // ripeterla nella tabella toglieva spazio a cio' che invece cambia.
        $howTo = __('Corrections <strong>are not made from this screen</strong>: the data belongs to the source platform. Each trail name carries two links — the <strong>name</strong> opens its record here, the <strong>icon</strong> next to it the one on the source platform: correct it there, and the next import removes the row on its own, assigning the number if it has meanwhile become assignable.');

        $columns = __('The <strong>Trail</strong> column always shows the one left without a number; the <strong>Detail</strong> shows why, next to the other side of the problem — a trail when someone else already has that code or the track is shared, a code when the problem is in the data.');

        $rows = [
            [__('Code already assigned'), __('Another trail already has that code: one of the two must be corrected at the source.')],
            [__('Sector mismatch'), __('The sector written in the code is not the one the track actually falls in. Next to it, the code the platform would compose from the geometry.')],
            [__('Duplicate geometry'), __('Two or more trails with the exact same track. Until it is known which one is right, none of them gets a number.')],
            [__('Unreadable code'), __('No valid number can be derived from the code field. Next to it, the first free number in the sector: it is a hint, not a decision — the right number is the one on the signage in the field.')],
            [__('Outside any sector'), __('The geometry does not fall in any sector, so there is no prefix to compose the code with.')],
        ];

        $list = '';

        foreach ($rows as [$label, $text]) {
            $list .= '<li class="mb-2"><strong>'.$label.'</strong> — '.$text.'</li>';
        }

        return '<h3 class="text-lg font-bold mb-3">'.__('What these rows are').'</h3>'
            .'<p class="mb-3">'.$intro.'</p>'
            .'<p class="mb-3">'.$columns.'</p>'
            .'<ul class="list-disc list-inside mb-3">'.$list.'</ul>'
            .'<p class="text-sm">'.$howTo.'</p>';
    }

    public function filters(NovaRequest $request): array
    {
        return [
            new TrailAnomalyTypeFilter,
        ];
    }
}
