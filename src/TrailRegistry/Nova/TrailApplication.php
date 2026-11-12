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
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\AbstractGeometryResource;
use Wm\WmPackage\Nova\Fields\PropertiesPanel;
use Wm\WmPackage\Nova\Traits\DispatchesDemOnDetail;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailGeometryException;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorExhaustedException;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorNotFoundException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication as TrailApplicationModel;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode as TrailRegistryCodeModel;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ApproveTrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\Actions\RejectTrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ReplaceTrailCodeNumber;
use Wm\WmPackage\TrailRegistry\Nova\Fields\TrailRegistryMap;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailApplicationSourceFilter;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailApplicationStatusFilter;
use Wm\WmPackage\TrailRegistry\Policies\TrailRegistryPolicy;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;
use Wm\WmPackage\TrailRegistry\TrailGeometryReader;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * @extends AbstractGeometryResource<TrailApplicationModel>
 */
class TrailApplication extends AbstractGeometryResource
{
    use DispatchesDemOnDetail, HidesWhenTrailRegistryDisabled, ResolvesCanonicalResources;

    public static $model = TrailApplicationModel::class;

    public static function newModel()
    {
        return static::newDomainModel(TrailApplicationModel::class, TrailRegistryClasses::application());
    }

    /**
     * Fissa, non derivata dal nome della classe: una sottoclasse dello shard
     * deve restare raggiungibile con la stessa chiave, gia' usata come
     * valore letterale in ComposesTrailRegistryMap.
     */
    public static function uriKey()
    {
        return 'trail-applications';
    }

    public static $title = 'name';

    public static $search = ['name'];

    public static function label(): string
    {
        return __('Applications');
    }

    public static function singularLabel(): string
    {
        return __('Application');
    }

    /**
     * Si modifica solo in istruttoria, e solo nei valori manuali del tab DEM
     * (vedi fieldsForUpdate): la traccia non cambia, quindi non cambiano
     * settore e prefisso del codice gia' comunicato. Approvata o respinta,
     * l'istanza e' uno storico (oc:8571).
     *
     * Ridefinisce il metodo del trait HidesWhenTrailRegistryDisabled, quindi
     * vanno rifatti qui sia il controllo del dominio spento sia la regola dei
     * ruoli del Catasto (oc:8700).
     */
    public function authorizedToUpdate(Request $request): bool
    {
        return static::trailRegistryEnabled()
            && TrailRegistryPolicy::allows($request->user())
            && $this->resource->status === TrailApplicationStatus::UnderReview;
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
            Text::make(__('Designation'), 'name')->sortable(),

            Text::make(__('Code'), fn () => $this->activeCode?->code),

            Text::make(__('Review status'), fn () => $this->status->label()),

            Text::make(__('Source'), 'source'),
        ];

        $userResource = static::resourceForModel(User::class);

        // La Resource utente vive nel consumer (`\App\Nova\User`), quindi si
        // risolve a runtime: se il consumer non ne monta nessuna, si ripiega
        // sul nome, invece di costruire un BelongsTo senza Resource.
        $fields[] = $userResource !== null
            ? BelongsTo::make(__('Entered by'), 'user', $userResource)->nullable()
            : Text::make(__('Entered by'), fn () => $this->user->name);

        $fields[] = DateTime::make(__('Submitted on'), 'created_at')->sortable();

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

        // Solo sulle respinte, e in sola lettura: la scrive l'azione «Respingi»
        // e un'istanza respinta non si modifica (oc:8567). Non in
        // summaryFields(), che alimenta anche l'index.
        $fields[] = Textarea::make(__('Rejection reason'), 'rejection_reason')
            ->onlyOnDetail()
            ->alwaysShow()
            ->canSee(fn () => $this->resource->status === TrailApplicationStatus::Rejected);

        // Il DEM manca se il job alla creazione e' fallito o se la geometria
        // e' senza quote: lo si rilancia qui, e solo dove serve (oc:8571, oc:8660).
        $this->dispatchDemOnDetail($request);

        $fields[] = TrailRegistryMap::make(__('Map'), 'geometry')->enableSlopeChart();

        $fields[] = Text::make(__('Legend'), function () {
            $code = $this->resource->mapCode();

            return $code === null ? '' : MapLegendRenderer::render($code, TrailRegistryCodeModel::MAP_SUBJECT_APPLICATION);
        })->asHtml()->onlyOnDetail();

        $fields[] = Text::make(__('Uploaded GPX/GeoJSON file'), function () {
            $media = $this->resource->getFirstMedia(TrailApplicationModel::ORIGINAL_GEOMETRY_COLLECTION);

            return $media === null ? '—' : sprintf('<a class="link-default" href="%s">%s</a>', e($media->getUrl()), e($media->file_name));
        })->asHtml()->onlyOnDetail();

        $fields[] = Tab::group(__('Details'), [
            Tab::make(__('DEM'), $this->getDemTabFields()),
            Tab::make(__('API data'), $this->apiDataFields()),
            Tab::make(__('Attachments'), $this->attachmentFields()),
        ]);

        return $fields;
    }

    /**
     * I dati che il canale API consegna, letti dalla colonna `api_data` (oc:8960).
     *
     * I Field li costruisce PropertiesPanel, lo stesso componente che rende
     * `properties` sulle altre Resource: dallo schema ricava il tipo di campo,
     * e un valore annidato finisce in un Code JSON invece di essere
     * concatenato a mano — che su una mappa di array mandava in 500 la detail
     * view.
     *
     * `onlyOnDetail()` si applica qui e non nello schema perche' il componente
     * nasconde dall'index e dall'update, non dalla creazione: questi dati
     * arrivano solo dal canale API e non si compilano a mano da nessuna parte.
     *
     * @return array<int, Field>
     */
    protected function apiDataFields(): array
    {
        $fields = (new PropertiesPanel(__('API data')))->jsonForm(
            $this->resource,
            TrailApplicationModel::API_DATA_COLUMN,
            '',
            $this->apiDataSchema(),
            false,
        );

        foreach ($fields as $field) {
            $field->onlyOnDetail();
        }

        return $fields;
    }

    /**
     * Lo schema dei campi che lo sportello consegna, nell'ordine del format
     * (campi 29-65). L'elenco non vive piu' qui: e' `ApplicationSchema`, che
     * lo shard puo' ridefinire nel container, e da cui derivano anche la
     * validazione dell'endpoint e i campi obbligatori.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function apiDataSchema(): array
    {
        return app(ApplicationSchema::class)->toNovaSchema();
    }

    /**
     * Gli allegati della domanda, uno per collection, in sola lettura.
     *
     * Entrano solo dal canale API, una chiamata per allegato, e qui sono
     * l'unico modo per vederli: senza questi campi l'istruttore ha davanti
     * una domanda di cui legge i dati ma non i documenti su cui si fonda.
     *
     * Sola lettura come il resto di cio' che arriva dal canale: un allegato
     * sbagliato non si sostituisce dall'ufficio, si rifa' la domanda.
     *
     * @return array<int, Field>
     */
    protected function attachmentFields(): array
    {
        $collections = [
            TrailApplicationModel::REPORT_COLLECTION => __('Report'),
            TrailApplicationModel::TRAIL_DATA_SHEET_COLLECTION => __('Trail data sheet'),
            TrailApplicationModel::AUTHORIZATIONS_COLLECTION => __('Authorizations'),
            TrailApplicationModel::MAINTENANCE_PLAN_COLLECTION => __('Maintenance plan'),
            TrailApplicationModel::PHOTOS_COLLECTION => __('Photographs'),
        ];

        $fields = [];

        foreach ($collections as $collection => $label) {
            $fields[] = Text::make($label, fn () => $this->renderAttachments($collection))
                ->asHtml()
                ->onlyOnDetail();
        }

        return $fields;
    }

    /**
     * I file di una collection: le immagini come anteprima, gli altri come
     * collegamento al nome del file.
     *
     * Una collection vuota non si nasconde, dice «non trasmesso»: la domanda
     * incompleta e' un'informazione per chi istruisce, e un campo che sparisce
     * si confonde con un campo che non esiste.
     */
    protected function renderAttachments(string $collection): string
    {
        $media = $this->resource->getMedia($collection);

        if ($media->isEmpty()) {
            return sprintf('<span class="text-gray-400">%s</span>', e(__('Not provided')));
        }

        return $media
            ->map(function ($item) {
                $url = $item->getUrl();

                if (str_starts_with((string) $item->mime_type, 'image/')) {
                    return sprintf(
                        '<a href="%s" target="_blank" rel="noopener"><img src="%s" alt="%s" style="height:96px;width:auto;border-radius:4px;margin:0 6px 6px 0;display:inline-block"></a>',
                        e($url),
                        e($url),
                        e($item->file_name),
                    );
                }

                return sprintf(
                    '<a class="link-default" href="%s" target="_blank" rel="noopener">%s</a>',
                    e($url),
                    e($item->file_name),
                );
            })
            ->implode(' ');
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
            Text::make(__('Designation'), 'name')->rules('required', 'max:255'),

            File::make(__('Geometry (GPX or GeoJSON)'), 'geometry')
                ->acceptedTypes('.gpx,.geojson,.json,application/gpx+xml,application/geo+json,application/json,text/xml')
                ->rules('required')
                ->help(__('The trail route: a GPX (track or route) or a GeoJSON with one or more lines.'))
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
     * Le tre action si offrono solo a chi gestisce il Catasto (oc:8700); le
     * due di istruttoria solo sulle istanze in istruttoria. La stessa
     * guardia e' ripetuta dentro handle(): questa risparmia all'operatore un
     * bottone che non puo' funzionare, quella e' il presidio.
     */
    public function actions(NovaRequest $request): array
    {
        // canRun() fa saltare a Nova l'autorizzazione della Resource
        // (ActionModelCollection::filterForExecution()): la regola dei ruoli
        // del Catasto (oc:8700) va ripetuta qui, o un POST all'Action passa.
        $canManage = fn ($request) => TrailRegistryPolicy::allows($request->user());
        $onlyUnderReview = fn ($request, $application) => $canManage($request)
            && $application->status === TrailApplicationStatus::UnderReview;

        return [
            (new ApproveTrailApplication)->canSee($canManage)->canRun($onlyUnderReview),
            (new RejectTrailApplication)->canSee($canManage)->canRun($onlyUnderReview),
            // Il vincolo che conta e' quello del service (solo Reserved):
            // qui lo replichiamo perche' il bottone non compaia quando non
            // porterebbe da nessuna parte.
            (new ReplaceTrailCodeNumber)->canSee($canManage)->canRun(
                fn ($request, $application) => $canManage($request)
                    && $application->activeCode?->status === TrailCodeStatus::Reserved,
            ),
        ];
    }
}
