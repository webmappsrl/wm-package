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
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * La lista di lavoro del gestore: cosa non va, su quale sentiero, e
 * possibilmente cosa scrivere per sistemarlo.
 *
 * Le anomalie non si correggono qui: il dato lo possiede la piattaforma di
 * origine, si sistema la scheda alla fonte e l'import successivo fa sparire
 * la riga da se'.
 *
 * **Astratta.** La tabella `trail_registry_anomalies` tiene due famiglie di righe, distinte dal
 * `source`, che si lavorano in modo opposto: un'anomalia riguarda un dato che appartiene alla
 * piattaforma di origine e si corregge la', un'incongruenza si risolve nel backoffice inserendo
 * il dato che manca. Le due hanno una Resource ciascuna — {@see TrailRegistryImportAnomaly} e
 * {@see TrailRegistryInconsistency} — perche' la spiegazione in cima all'elenco dice, per l'una,
 * il contrario di quel che vale per l'altra, e una schermata sola darebbe a meta' di chi la legge
 * l'istruzione sbagliata (oc:8989).
 *
 * Qui sta tutto cio' che non cambia: il modello, le colonne, la mappa, il titolo delle righe, i
 * divieti di creare, modificare e cancellare. Una sottoclasse dichiara la chiave, le etichette,
 * quali righe mostra, con quale filtro e con quale spiegazione in testa.
 *
 * @extends resource<\Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly>
 */
abstract class TrailRegistryAnomaly extends Resource
{
    use HidesWhenTrailRegistryDisabled, ResolvesCanonicalResources;

    public static $model = \Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly::class;

    public static function newModel()
    {
        return static::newDomainModel(\Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly::class, TrailRegistryClasses::anomaly());
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

    /**
     * Il testo della spiegazione in cima: lo scrive ogni sottoclasse, perche' e' esattamente
     * cio' che distingue le due schermate.
     */
    abstract protected function noticeBody(): string;

    /**
     * Nessun filtro da qui: i due registri dei tipi sono distinti, e offrire quello sbagliato
     * darebbe scelte che non selezionano nessuna riga. Lo dichiara ogni sottoclasse.
     *
     * Non e' astratto perche' `Laravel\Nova\Resource` lo implementa gia': PHP non permette di
     * rendere astratto un metodo che la classe padre ha concreto.
     *
     * @return array<int, \Laravel\Nova\Filters\Filter>
     */
    public function filters(NovaRequest $request)
    {
        return [];
    }
}
