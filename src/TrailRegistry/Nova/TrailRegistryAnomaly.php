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
            default => __('anomalia'),
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
        return __('Anomalie');
    }

    public static function singularLabel(): string
    {
        return __('Anomalia');
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
        $fields[] = Text::make(__('Tipo'), function () {
            $type = $this->type;

            return $type instanceof TrailRegistryAnomalyType
                ? $type->value
                : ($type !== null ? TrailRegistryAnomalyTypes::label($type) : null);
        })->sortable();

        // Una sola colonna, con dentro una tabellina etichetta/valore le cui
        // righe cambiano con il tipo di anomalia: vedi AnomalyDetailRenderer.
        $fields[] = Text::make(
            __('Dettaglio'),
            fn () => AnomalyDetailRenderer::render($this->resource),
        )->asHtml();

        // Il sentiero collegato — l'assegnatario del codice, o il primo dei
        // gemelli — resta raggiungibile anche dentro il pannello, oltre che
        // dal collegamento nella descrizione.
        if ($trackResource !== null) {
            $fields[] = BelongsTo::make(__('Sentiero collegato'), 'relatedEcTrack', $trackResource)
                ->nullable()
                ->onlyOnDetail();
        }

        $fields[] = DateTime::make(__('Rilevata il'), 'created_at')->sortable();

        // La mappa solo nella scheda: nell'elenco sarebbe una riga alta come
        // uno schermo per ogni anomalia. Cosa disegna lo decide il modello
        // (TrailRegistryAnomaly::getFeatureCollectionMap()), che varia le
        // feature secondo il tipo.
        $fields[] = TrailRegistryMap::make(__('Mappa'), 'geometry');

        $fields[] = Text::make(
            __('Legenda'),
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
            __('Sentiero'),
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
        $intro = __('Sono i sentieri che <strong>non hanno ottenuto un numero</strong>, e il motivo per cui non l’hanno ottenuto. Nel registro dei codici entrano solo i codici su cui non pende alcun dubbio: tutto ciò che resta irrisolto sta qui, e finché sta qui quel sentiero è senza numero.');

        // L'azione sta qui e non su ogni riga: e' la stessa per tutte, e
        // ripeterla nella tabella toglieva spazio a cio' che invece cambia.
        $howTo = __('Le correzioni <strong>non si fanno da questa schermata</strong>: il dato appartiene alla piattaforma di origine. Ogni nome di sentiero porta due collegamenti — il <strong>nome</strong> apre la sua scheda qui, l’<strong>icona</strong> accanto quella sulla piattaforma di origine: si corregge lì, e l’importazione successiva fa sparire la riga da sé, assegnando il numero se nel frattempo è diventato assegnabile.');

        $columns = __('Nella colonna <strong>Sentiero</strong> c’è sempre quello rimasto senza numero; nel <strong>Dettaglio</strong> il perché, con accanto l’altro termine del problema — un sentiero quando quel codice ce l’ha già qualcun altro o la traccia è condivisa, un codice quando il problema sta nel dato.');

        $rows = [
            [__('Codice già assegnato'), __('Quel codice ce l’ha già un altro sentiero: uno dei due va corretto alla fonte.')],
            [__('Settore discordante'), __('Il settore scritto nel codice non è quello in cui la traccia ricade davvero. Accanto, il codice che la piattaforma comporrebbe dalla geometria.')],
            [__('Geometria duplicata'), __('Due o più sentieri con la stessa identica traccia. Finché non si sa quale sia quello buono, nessuno di loro prende un numero.')],
            [__('Codice illeggibile'), __('Dal campo del codice non si ricava un numero valido. Accanto, il primo numero libero del settore: è un’indicazione, non una decisione — il numero giusto è quello sulla segnaletica in campo.')],
            [__('Fuori da ogni settore'), __('La geometria non ricade in alcun settore, quindi non esiste un prefisso con cui comporre il codice.')],
        ];

        $list = '';

        foreach ($rows as [$label, $text]) {
            $list .= '<li class="mb-2"><strong>'.$label.'</strong> — '.$text.'</li>';
        }

        return '<h3 class="text-lg font-bold mb-3">'.__('Che cosa sono queste righe').'</h3>'
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
