<?php

namespace Wm\WmPackage\Nova;

use Illuminate\Database\Eloquent\Builder;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Wm\WmPackage\Models\TaxonomyTerm as TaxonomyTermModel;
use Wm\WmPackage\Nova\Fields\IconSelect\IconSelect;
use Wm\WmPackage\Nova\Filters\TaxonomyTermKindFilter;
use Wm\WmPackage\Nova\Filters\TermsByTaxonomyFilter;
use Wm\WmPackage\Support\TaxonomyTermLabel;

/**
 * Tassonomie e termini del modello unico di classificazione (oc:8910). Senza «Tassonomieo» la riga è
 * un tassonomia, con «Tassonomieo» è un termine di quelil tassonomia: da qui se ne crea una nuova senza
 * passare dal codice.
 */
class TaxonomyTerm extends AbstractTaxonomyResource
{
    public static $model = TaxonomyTermModel::class;

    public static $title = 'identifier';

    public static $search = ['id', 'identifier', 'name'];

    /**
     * Titolo della riga: il nome tradotto più l'identifier.
     *
     * Con il solo identifier la tendina «Tassonomieo» e i risultati della ricerca mostrano chiavi in
     * inglese, e capire di quale classificazione si tratti richiede di aprire la riga.
     */
    public function title(): string
    {
        // Nova costruisce i campi anche su un'istanza vuota: lì non c'è niente da etichettare.
        return $this->resource->exists
            ? TaxonomyTermLabel::for($this->resource)
            : (string) $this->identifier;
    }

    /**
     * @return array<int, Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [
            new TaxonomyTermKindFilter,
            new TermsByTaxonomyFilter,
        ];
    }

    public static function label(): string
    {
        return __('Classification terms');
    }

    public static function singularLabel(): string
    {
        return __('Classification term');
    }

    /**
     * La riga in lavorazione è un tassonomia?
     *
     * Vale per le regole dell'identifier, più strette per i tassonomie. Una Resource ristretta a
     * una soil tassonomia (AbstractTaxonomyTermsResource) qui crea sempre termini e risponde `false`.
     */
    protected function isCreatingVocabulary(NovaRequest $request): bool
    {
        return ! $request->input('parent');
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make(__('Taxonomy'), 'parent', self::class)
                ->nullable()
                ->sortable()
                ->relatableQueryUsing(function (NovaRequest $request, Builder $query) {
                    $query->whereNull('parent_id');
                    // Un tassonomia non può essere padre di sé stessa.
                    if ($request->resourceId) {
                        $query->whereKeyNot($request->resourceId);
                    }
                })
                ->rules(function (NovaRequest $request) {
                    return [function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                        $id = $request->resourceId;
                        if ($value && $id && TaxonomyTermModel::where('parent_id', $id)->exists()) {
                            $fail(__('This vocabulary already has terms: it cannot be moved under another vocabulary.'));
                        }
                    }];
                })
                ->help(__('Leave empty to create a new vocabulary.')),
            Text::make(__('Identifier'), 'identifier')
                ->sortable()
                ->required()
                ->rules('required', function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                    // Il formato vale per i tassonomie (vedi TaxonomyTermModel::FAMILY_IDENTIFIER_FORMAT).
                    if ($this->isCreatingVocabulary($request) && ! preg_match(TaxonomyTermModel::FAMILY_IDENTIFIER_FORMAT, (string) $value)) {
                        $fail(__('The identifier of a vocabulary must be lowercase letters, digits and hyphens, with colons allowed.'));
                    }
                })
                ->creationRules('unique:taxonomy_terms,identifier')
                ->updateRules('unique:taxonomy_terms,identifier,{{resourceId}}')
                ->help(__('For a vocabulary: lowercase letters, digits and hyphens, with colons allowed (e.g. hiking-difficulty). No dots: Elasticsearch would split them into nested objects.')),
            // Il nome vive dentro NovaTabTranslatable, che non compare in elenco: senza questa
            // colonna la lista mostra solo identifier in inglese.
            Text::make(__('Name'), fn () => $this->resource->exists ? TaxonomyTermLabel::for($this->resource) : null)
                ->onlyOnIndex(),
            NovaTabTranslatable::make([
                Text::make(__('Name'), 'name'),
                Textarea::make(__('Description'), 'description'),
                Textarea::make(__('Excerpt'), 'excerpt'),
            ])->hideFromIndex(),
            // Acceso o spento, app per app (oc:8910). Solo sui tassonomie: un termine segue
            // il suo. Di sistema vuol dire portato dal package: nasce acceso in ogni app nuova.
            Boolean::make(__('System taxonomy'), 'is_system')
                ->help(__('A system vocabulary is enabled in every new app.'))
                ->canSee(fn () => $this->resource->exists === false || $this->resource->isTaxonomy())
                ->hideFromIndex(),
            // Dove è acceso, in sola lettura: si accende e si spegne dalla scheda dell'app,
            // che è il posto in cui si configura un'app (oc:8910).
            Text::make(__('Enabled in apps'), fn () => $this->resource->exists && $this->resource->isTaxonomy()
                ? ($this->resource->apps()->pluck('apps.name')->implode(', ') ?: '—')
                : null)
                ->onlyOnDetail()
                ->canSee(fn () => $this->resource->exists && $this->resource->isTaxonomy()),
            IconSelect::make(__('Icon'), 'icon')
                ->loadFromIconsFile()
                ->hideFromIndex(),
            Number::make(__('Sort order'), 'sort_order')
                ->default(0)
                ->sortable()
                ->help(__('Terms are listed by this number, lowest first: use it for ordinal scales.')),
            Boolean::make(__('Single choice'), 'single_choice')
                ->help(__('For a vocabulary: a content can have only one term of this vocabulary.')),
            Code::make(__('Properties'), 'properties')->json(),

            // I contenuti che portano questo termine. Il legame è sul termine, non sulla
            // tassonomia: su un tassonomia le due liste sono vuote per costruzione, e si nascondono.
            MorphToMany::make(__('EC Tracks'), 'ecTracks', $this->ecTrackResource())
                ->display('name')
                ->collapsedByDefault()
                ->canSee(fn () => $this->resource->exists && ! $this->resource->isTaxonomy()),
            MorphToMany::make(__('EC Pois'), 'ecPois', $this->ecPoiResource())
                ->display('name')
                ->collapsedByDefault()
                ->canSee(fn () => $this->resource->exists && ! $this->resource->isTaxonomy()),
        ];
    }

    /**
     * Resource Nova dei contenuti collegati.
     *
     * Uno shard registra le proprie (`app/Nova`) e non quelle del package: se le liste puntassero
     * qui a classi non registrate, i link del dettaglio non porterebbero da nessuna parte.
     *
     * @return class-string<\Laravel\Nova\Resource>
     */
    protected function ecTrackResource(): string
    {
        return EcTrack::class;
    }

    /**
     * @return class-string<\Laravel\Nova\Resource>
     */
    protected function ecPoiResource(): string
    {
        return EcPoi::class;
    }
}
