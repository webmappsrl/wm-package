<?php

namespace Wm\WmPackage\Nova;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Hidden;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\TaxonomyTerm as TaxonomyTermModel;
use Wm\WmPackage\Support\TaxonomyTermLabel;

/**
 * Vista Nova dedicata ai termini di una sola tassonomia (oc:8910).
 *
 * Serve quando una tassonomia merita una voce di menu propria — le avvertenze di Forestas, che
 * prima erano una tassonomia a sé — invece di mandare chi la usa nell'elenco unico dei termini
 * con un filtro addosso. Una sottoclasse dichiara solo l'identifier della tassonomia: l'elenco si
 * restringe ai suoi termini, la creazione nasce già dentro, ed etichette e titolo vengono dal
 * nome della tassonomia.
 *
 * L'`uriKey` resta quello che Nova deriva dal nome della classe: una sottoclasse che vuole
 * conservare un indirizzo già in uso lo ridefinisce.
 */
abstract class AbstractTaxonomyTermsResource extends TaxonomyTerm
{
    /**
     * Identifier della tassonomia di cui questa Resource mostra i termini.
     */
    abstract public static function taxonomyIdentifier(): string;

    /**
     * La tassonomia di questa Resource, o `null` se non è ancora stata creata.
     *
     * Si rilegge a ogni chiamata, e sono poche per richiesta: una memoria statica
     * sopravvivrebbe alla richiesta, e in un worker di coda — o fra due test — terrebbe l'id di
     * una tassonomia che non c'è più, facendo rispondere l'elenco vuoto senza dire perché.
     */
    public static function taxonomy(): ?TaxonomyTermModel
    {
        return TaxonomyTermModel::query()
            ->taxonomies()
            ->where('identifier', static::taxonomyIdentifier())
            ->first();
    }

    public static function label(): string
    {
        $taxonomy = static::taxonomy();

        return $taxonomy ? TaxonomyTermLabel::nameOf($taxonomy) : static::taxonomyIdentifier();
    }

    public static function singularLabel(): string
    {
        return static::label();
    }

    /**
     * @param  Builder<TaxonomyTermModel>  $query
     * @return Builder<TaxonomyTermModel>
     */
    public static function indexQuery(NovaRequest $request, $query)
    {
        // Tipo non ancora creata: l'elenco è vuoto, non è l'elenco di tutti i termini.
        return $query->where('parent_id', static::taxonomy()?->id ?? 0);
    }

    /**
     * Qui si creano termini, mai tassonomie: l'identifier non segue il formato delle tassonomie.
     */
    protected function isCreatingTaxonomy(NovaRequest $request): bool
    {
        return false;
    }

    public function fields(NovaRequest $request): array
    {
        $fields = [];
        foreach (parent::fields($request) as $field) {
            // La tassonomia non si sceglie: è quella della Resource, e si scrive da sé.
            if ($field instanceof BelongsTo && $field->attribute === 'parent') {
                $fields[] = Hidden::make(__('Taxonomy'), 'parent_id')
                    ->default(fn () => static::taxonomy()?->id);

                continue;
            }
            $fields[] = $field;
        }

        return $fields;
    }
}
