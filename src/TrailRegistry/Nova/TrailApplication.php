<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\AbstractGeometryResource;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailGeometryException;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorExhaustedException;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorNotFoundException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication as TrailApplicationModel;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ApproveTrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\Actions\RejectTrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ReplaceTrailCodeNumber;
use Wm\WmPackage\TrailRegistry\Nova\Fields\TrailRegistryMap;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailApplicationSourceFilter;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailApplicationStatusFilter;
use Wm\WmPackage\TrailRegistry\TrailGeometryReader;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * @extends AbstractGeometryResource<TrailApplicationModel>
 */
class TrailApplication extends AbstractGeometryResource
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
     * Si modifica solo in istruttoria, e solo nei valori manuali del tab DEM
     * (vedi fieldsForUpdate): la traccia non cambia, quindi non cambiano
     * settore e prefisso del codice gia' comunicato. Approvata o rifiutata,
     * l'istanza e' uno storico (oc:8571).
     */
    public function authorizedToUpdate(Request $request): bool
    {
        return $this->resource->status === TrailApplicationStatus::UnderReview;
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

    /**
     * Le sei colonne dell'index (e del dettaglio): denominazione, codice,
     * stato istruttoria, provenienza, chi l'ha inserita e quando. Estratte
     * in un metodo perche' fields() e fieldsForIndex() non le duplichino
     * (oc:8571).
     *
     * @return array<int, Field>
     */
    protected function summaryFields(NovaRequest $request): array
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

        return $fields;
    }

    /**
     * Le sei colonne decise per l'index: niente colonne DEM, che nel tab si
     * sarebbero appiattite in cinque colonne aggiuntive (oc:8571).
     *
     * @return array<int, Field>
     */
    public function fieldsForIndex(NovaRequest $request): array
    {
        return $this->summaryFields($request);
    }

    public function fields(NovaRequest $request): array
    {
        $fields = $this->summaryFields($request);

        if ($request->isResourceDetailRequest()) {
            // Il DEM manca solo se il job alla creazione e' fallito: lo si
            // rilancia qui, e solo dove serve (oc:8571).
            $this->resource->dispatchDemIfMissing();
        }

        $fields[] = TrailRegistryMap::make(__('Mappa'), 'geometry');

        $fields[] = Text::make(__('Legenda'), function () {
            $code = $this->resource->mapCode();

            return $code === null ? '' : MapLegendRenderer::render($code);
        })->asHtml()->onlyOnDetail();

        $fields[] = Text::make(__('File GPX/GeoJSON caricato'), function () {
            $media = $this->resource->getFirstMedia(TrailApplicationModel::ORIGINAL_GEOMETRY_COLLECTION);

            return $media === null ? '—' : sprintf('<a class="link-default" href="%s">%s</a>', e($media->getUrl()), e($media->file_name));
        })->asHtml()->onlyOnDetail();

        $fields[] = Tab::group(__('Dettagli'), [
            Tab::make(__('DEM'), $this->getDemTabFields()),
        ]);

        return $fields;
    }

    /**
     * I nove valori manuali del tab DEM, presi da getDemTabFields() e non
     * riscritti. Gli altri Field del tab scrivono in `dem_data` e non devono
     * finire nel form: il calcolato e' il riferimento del confronto.
     *
     * @return array<int, Field>
     */
    protected function manualDemFields(): array
    {
        return array_values(array_filter(
            $this->getDemTabFields(),
            fn ($field) => $field instanceof Field
                && str_starts_with((string) $field->attribute, 'properties->manual_data->'),
        ));
    }

    public function fieldsForUpdate(NovaRequest $request): array
    {
        return $this->manualDemFields();
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
                // Lo `store` callback estrae la geometria e la scrive
                // sull'attributo; il file resta comunque conservato in
                // `original_geometry` (vedi afterCreate()), come riferimento
                // di cio' che e' stato dichiarato.
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

        // Il file originale si conserva solo dopo la riserva: se questa
        // fallisce la transazione va in rollback e non deve restare un
        // allegato orfano.
        $file = $request->file('geometry');

        if ($file !== null) {
            $model->addMedia($file->getRealPath())
                ->preservingOriginal()
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection(TrailApplicationModel::ORIGINAL_GEOMETRY_COLLECTION);
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
            // Il vincolo che conta e' quello del service (solo Reserved):
            // qui lo replichiamo perche' il bottone non compaia quando non
            // porterebbe da nessuna parte.
            (new ReplaceTrailCodeNumber)->canRun(
                fn ($request, $application) => $application->activeCode?->status === TrailCodeStatus::Reserved,
            ),
        ];
    }
}
