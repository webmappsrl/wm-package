<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ReplaceTrailCodeNumber;
use Wm\WmPackage\TrailRegistry\Nova\Fields\TrailRegistryMap;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailCodeAreaFilter;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailCodeOriginFilter;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailCodeProvinceFilter;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailCodeSectorFilter;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailCodeStatusFilter;

/**
 * @extends resource<\Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode>
 */
class TrailRegistryCode extends Resource
{
    use ResolvesCanonicalResources;

    public static $model = \Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode::class;

    public static $title = 'code';

    /**
     * Vuoto di proposito: la ricerca non passa dalle colonne ma da
     * {@see static::applySearch()}, che ricompone il codice in SQL.
     *
     * `number` e' una colonna intera e la ricerca di Nova ci costruirebbe
     * sopra un `ilike`, che in PostgreSQL fallisce; e il codice in uscita —
     * l'unica stringa che si cerca davvero — non e' una colonna, si compone
     * da sei.
     */
    public static $search = [];

    /**
     * L'elenco esce ordinato per codice.
     *
     * L'ordinamento e' sulle sei colonne e non sulla stringa ricomposta: da'
     * lo stesso ordine — sono le parti del codice, in ordine di lettura — ma
     * passa dall'indice, mentre un ORDER BY su un'espressione costringerebbe
     * a ordinare l'intera tabella a ogni pagina. `number` e' un intero, quindi
     * il 9 viene prima del 10 invece che dopo, come accadrebbe ordinando la
     * stringa senza lo zero davanti.
     *
     * Nova applica il proprio ordinamento quando l'utente clicca su una
     * colonna: questo e' solo il criterio di partenza.
     */
    public static function indexQuery(NovaRequest $request, $query)
    {
        if (empty($request->query('orderBy'))) {
            $query->getQuery()->orders = [];

            $query->orderBy('region')
                ->orderBy('province')
                ->orderBy('area')
                ->orderBy('sector')
                ->orderBy('number')
                ->orderBy('variant');
        }

        return $query;
    }

    /**
     * Cerca per codice, ricomponendolo dalle sei colonne direttamente in SQL.
     *
     * Serve a raggiungere una posizione: cercando `ZORT511` compare la riga
     * che la occupa. Chi resta senza numero perche' la posizione e' di un
     * altro non e' qui — il registro ospita solo codici realmente portati da
     * qualcuno — ma nelle anomalie, dove il sentiero rimasto senza numero e
     * quello che il numero lo porta stanno sulla stessa riga, senza doverli
     * far combaciare a mano.
     *
     * La ricerca e' per prefisso, quindi funziona anche parziale: `ZORT5`
     * elenca l'intero settore, `ZORT511` la sola posizione contesa.
     * Il confronto e' su `variant` reale, ma la stringa ricomposta omette lo
     * `0` come fa l'accessor `code`, altrimenti si cercherebbe una forma che
     * l'utente non vede mai.
     */
    public static function applySearch(Builder $query, string $search): Builder
    {
        $needle = strtoupper(trim($search));

        if ($needle === '') {
            return $query;
        }

        return $query->whereRaw(
            "region || province || area || sector || lpad(number::text, 2, '0')
             || CASE WHEN variant = '0' THEN '' ELSE variant END LIKE ?",
            [$needle.'%'],
        );
    }

    public static function label(): string
    {
        return __('Registro dei codici');
    }

    public static function singularLabel(): string
    {
        return __('Codice del registro');
    }

    /**
     * Un codice non si crea e non si modifica da un form: cambiare `number` su
     * una riga assegnata significherebbe cambiare un numero gia' comunicato al
     * richiedente e magari gia' stampato sulla segnaletica. Le righe le scrive
     * solo TrailRegistryService.
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
        $fields = [
            // Niente ->sortable(): non c'e' una colonna `code` su cui ordinare.
            Text::make(__('Codice'), fn () => $this->code),

            // Il nome del sentiero se assegnato, altrimenti quello
            // dell'istanza: l'unico appiglio leggibile in un elenco di codici.
            Text::make(__('Denominazione'), fn () => $this->denomination),

            Text::make(__('Stato'), fn () => $this->status->value),

            // Prima cosa che l'index rende visibile: quali codici sono letti
            // da un campo, quali dedotti da un nome, quali proposti d'ufficio.
            Text::make(__('Provenienza'), fn () => $this->origin->value),

            BelongsTo::make(__('Istanza'), 'application', TrailApplication::class)->nullable(),
        ];

        $trackResource = static::resourceForModel(
            EcTrack::class,
            \Wm\WmPackage\Nova\EcTrack::class,
        );

        if ($trackResource !== null) {
            $fields[] = BelongsTo::make(__('Sentiero'), 'ecTrack', $trackResource)->nullable();
        }

        // Le sei colonne scomposte hanno senso solo nella scheda del singolo
        // codice. La variante si mostra per il suo valore reale, `0` incluso:
        // l'omissione riguarda il codice in uscita.
        $fields = array_merge($fields, [
            Text::make(__('Regione'), 'region')->onlyOnDetail(),
            Text::make(__('Provincia'), 'province')->onlyOnDetail(),
            Text::make(__('Area'), 'area')->onlyOnDetail(),
            Text::make(__('Settore'), 'sector')->onlyOnDetail(),
            Number::make(__('Numero'), 'number')->onlyOnDetail(),
            Text::make(__('Variante'), 'variant')->onlyOnDetail(),
        ]);

        $whereResource = static::resourceForModel(
            TaxonomyWhere::class,
            \Wm\WmPackage\Nova\TaxonomyWhere::class,
        );

        if ($whereResource !== null) {
            $fields[] = BelongsTo::make(__('Settore di riferimento'), 'taxonomyWhere', $whereResource)
                ->nullable()
                ->onlyOnDetail();
        }

        // La mappa: settore, sentiero e istanza insieme, cosi' si vede a colpo
        // d'occhio perche' quel codice ha quel prefisso e a chi appartiene.
        // La rotta del campo risale al modello dall'elenco delle Resource di
        // Nova, quindi non serve dichiarare l'endpoint a mano.
        $fields[] = TrailRegistryMap::make(__('Mappa'), 'geometry');

        // La legenda e' HTML statico accanto alla mappa, non un componente
        // dentro di essa: vedi MapLegendRenderer per il perche'.
        $fields[] = Text::make(
            __('Legenda'),
            fn () => MapLegendRenderer::render($this->resource),
        )->asHtml()->onlyOnDetail();

        $fields[] = Text::make(
            __('Storia dei cambi di stato'),
            fn () => CodeHistoryRenderer::render($this->resource),
        )->asHtml()->onlyOnDetail();

        return $fields;
    }

    public function filters(NovaRequest $request): array
    {
        return [
            new TrailCodeProvinceFilter,
            new TrailCodeAreaFilter,
            new TrailCodeSectorFilter,
            new TrailCodeStatusFilter,
            new TrailCodeOriginFilter,
        ];
    }

    /**
     * Nessuna action «Libera numero»: sarebbe l'unica operazione capace di
     * liberare un codice senza che sia accaduto nulla, e lascerebbe nella
     * storia un passaggio privo di causa. La liberazione e' sempre conseguenza
     * di un atto — l'istanza respinta, o il sentiero deaccatastato.
     */
    public function actions(NovaRequest $request): array
    {
        return [
            (new ReplaceTrailCodeNumber)->canRun(
                fn ($request, $code) => $code->status === TrailCodeStatus::Reserved,
            ),
        ];
    }
}
