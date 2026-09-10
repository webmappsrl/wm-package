<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailGeometryException;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorExhaustedException;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorNotFoundException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication as TrailApplicationModel;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ApproveTrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\Actions\RejectTrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailApplicationSourceFilter;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailApplicationStatusFilter;
use Wm\WmPackage\TrailRegistry\TrailGeometryReader;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * @extends resource<TrailApplicationModel>
 */
class TrailApplication extends Resource
{
    use ResolvesCanonicalResources;

    public static $model = TrailApplicationModel::class;

    public static $title = 'name';

    public static $search = ['name'];

    public static function label(): string
    {
        return __('Istanze');
    }

    public static function singularLabel(): string
    {
        return __('Istanza');
    }

    /**
     * Nessun form di modifica in questo ciclo: cosa sia modificabile dopo la
     * presentazione e' una domanda aperta con il cliente — se la traccia
     * cambiasse, potrebbe cambiare il settore e quindi il prefisso di un
     * codice gia' comunicato.
     */
    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    /**
     * Nemmeno la cancellazione: un'istanza ha sempre almeno un codice nel
     * registro che la referenzia, e senza questo diniego l'operatore
     * otterrebbe una violazione di chiave esterna invece di una risposta
     * comprensibile.
     */
    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    public function fields(NovaRequest $request): array
    {
        $fields = [
            Text::make(__('Denominazione'), 'name')->sortable(),

            Text::make(__('Codice'), fn () => $this->activeCode?->code),

            Text::make(__('Stato istruttoria'), fn () => $this->status->value),

            Text::make(__('Provenienza'), 'source'),
        ];

        $userResource = static::resourceForModel(User::class);

        // La Resource utente vive nel consumer (`\App\Nova\User`), quindi si
        // risolve a runtime: se il consumer non ne monta nessuna, si ripiega
        // sul nome, invece di costruire un BelongsTo senza Resource.
        $fields[] = $userResource !== null
            ? BelongsTo::make(__('Inserita da'), 'user', $userResource)->nullable()
            : Text::make(__('Inserita da'), fn () => $this->user->name);

        $fields[] = DateTime::make(__('Presentata il'), 'created_at')->sortable();

        $fields[] = Code::make(__('Proprietà'), 'properties')->json()->onlyOnDetail();

        return $fields;
    }

    /**
     * Il form dell'istanza d'ufficio: all'operatore si chiede solo cosa non
     * si puo' dedurre.
     *
     * `source`, `status` e `user_id` non compaiono perche' non sono una
     * scelta: un'istanza nata qui e' per definizione d'ufficio, nasce in
     * istruttoria, e chi l'ha inserita e' l'utente autenticato. Li scrive
     * beforeCreate().
     *
     * @return array<int, Field>
     */
    public function fieldsForCreate(NovaRequest $request): array
    {
        return [
            Text::make(__('Denominazione'), 'name')->rules('required', 'max:255'),

            File::make(__('Geometria (GPX o GeoJSON)'), 'geometry')
                ->acceptedTypes('.gpx,.geojson,.json,application/gpx+xml,application/geo+json,application/json,text/xml')
                ->rules('required')
                ->help(__('Il tracciato del sentiero: un GPX (traccia o rotta) oppure un GeoJSON con una o piu\' linee.'))
                // Il file non si conserva: interessa la geometria, non
                // l'allegato. Lo `store` callback la estrae e la scrive
                // sull'attributo, nessun byte finisce sullo storage.
                ->store(fn (NovaRequest $request) => static::geometryFrom($request)),
        ];
    }

    /**
     * La geometria del file caricato, pronta per la colonna
     * `geography(MultiLineStringZ, 4326)`.
     *
     * Un file illeggibile diventa un errore di validazione sul campo, non una
     * eccezione non gestita: l'operatore deve vedere *perche'* il tracciato e'
     * stato rifiutato, sul form, accanto al campo che lo ha caricato.
     */
    protected static function geometryFrom(NovaRequest $request): Expression
    {
        $file = $request->file('geometry');

        try {
            $wkt = app(TrailGeometryReader::class)->wktFrom(
                (string) file_get_contents($file->getRealPath())
            );
        } catch (InvalidTrailGeometryException $e) {
            throw ValidationException::withMessages(['geometry' => $e->getMessage()]);
        }

        // Il WKT e' composto da TrailGeometryReader a partire da float, quindi
        // contiene solo cifre, segni, punti, virgole e parentesi.
        return DB::raw("ST_GeomFromText('{$wkt}', 4326)");
    }

    /**
     * Cio' che l'operatore non decide.
     */
    public static function beforeCreate(NovaRequest $request, Model $model): void
    {
        assert($model instanceof TrailApplicationModel);

        $model->source = 'office';
        $model->status = TrailApplicationStatus::UnderReview;
        $model->user_id = $request->user()->id;
    }

    /**
     * La riserva del numero fa parte della creazione, non e' un passo
     * successivo: un'istanza senza codice nel registro non esiste in questo
     * dominio — la riga `riservato` E' la prova che la prevalidazione e'
     * passata (vedi il commento sul modello).
     *
     * ResourceStoreController avvolge fill/save/afterCreate in un'unica
     * transazione, quindi un settore non trovato o esaurito qui non lascia
     * dietro un'istanza orfana: rimane un errore sul campo geometria.
     */
    public static function afterCreate(NovaRequest $request, Model $model): void
    {
        assert($model instanceof TrailApplicationModel);

        try {
            app(TrailRegistryService::class)->reserve($model);
        } catch (SectorNotFoundException|SectorExhaustedException $e) {
            throw ValidationException::withMessages(['geometry' => $e->getMessage()]);
        }
    }

    public function filters(NovaRequest $request): array
    {
        return [
            new TrailApplicationStatusFilter,
            new TrailApplicationSourceFilter,
        ];
    }

    /**
     * Le due action si offrono solo sulle istanze in istruttoria. La stessa
     * guardia e' ripetuta dentro handle(): questa risparmia all'operatore un
     * bottone che non puo' funzionare, quella e' il presidio.
     */
    public function actions(NovaRequest $request): array
    {
        $onlyUnderReview = fn ($request, $application) => $application->status === TrailApplicationStatus::UnderReview;

        return [
            (new ApproveTrailApplication)->canRun($onlyUnderReview),
            (new RejectTrailApplication)->canRun($onlyUnderReview),
        ];
    }
}
