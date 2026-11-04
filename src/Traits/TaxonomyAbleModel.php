<?php

namespace Wm\WmPackage\Traits;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Models\TaxonomyTarget;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Models\TaxonomyTermable;
use Wm\WmPackage\Models\TaxonomyTheme;
use Wm\WmPackage\Models\TaxonomyWhen;
use Wm\WmPackage\Models\TaxonomyWhere;

trait TaxonomyAbleModel
{
    public function taxonomyWhens(): MorphToMany
    {
        return $this->morphToMany(TaxonomyWhen::class, 'taxonomy_whenable');
    }

    public function taxonomyTargets(): MorphToMany
    {
        return $this->morphToMany(TaxonomyTarget::class, 'taxonomy_targetable');
    }

    public function taxonomyActivities(): MorphToMany
    {
        return $this->morphToMany(TaxonomyActivity::class, 'taxonomy_activityable')
            ->withPivot(['duration_forward', 'duration_backward']);
    }

    public function taxonomyPoiTypes(): MorphToMany
    {
        return $this->morphToMany(TaxonomyPoiType::class, 'taxonomy_poi_typeable');
    }

    public function taxonomyThemes(): MorphToMany
    {
        return $this->morphToMany(TaxonomyTheme::class, 'taxonomy_themeable');
    }

    public function taxonomyWheres(): MorphToMany
    {
        return $this->morphToMany(TaxonomyWhere::class, 'taxonomy_whereable');
    }

    /**
     * Termini del modello unico di classificazione (oc:8910). using() serve perché la relazione
     * copi la tassonomia su parent_id al salvataggio. with('parent') porta con i termini anche la
     * loro tassonomia, così terms() e term() ricavano l'identifier senza interrogare il database.
     * I termini escono per sort_order e poi per id: le scale sono ordinali (T < E < EE < EEA) e
     * term() prende il primo di questo ordine.
     *
     * @return MorphToMany<TaxonomyTerm, $this, TaxonomyTermable, 'pivot'>
     */
    public function taxonomyTerms(): MorphToMany
    {
        return $this->morphToMany(TaxonomyTerm::class, 'termable', 'taxonomy_termables')
            ->using(TaxonomyTermable::class)
            ->withPivot(['parent_id', 'properties'])
            ->with('parent')
            ->orderBy('taxonomy_terms.sort_order')
            ->orderBy('taxonomy_terms.id');
    }

    /**
     * Termini di una tassonomia (oc:8910). Non interroga il database: filtra la collection già
     * caricata di taxonomyTerms (caricata qui una volta sola se manca), leggendo l'identifier
     * della tassonomia dal padre caricato con ogni termine. Una sola fonte: la gerarchia viva.
     *
     * Due casi restituiscono una collection vuota senza segnalare nulla: una tassonomia attaccata
     * direttamente al contenuto non è un termine di sé stessa e non compare mai; un identifier
     * che non esiste (un refuso) è indistinguibile da una tassonomia senza termini, perché
     * verificarlo costerebbe una query.
     *
     * @return Collection<int, TaxonomyTerm>
     */
    public function terms(string $taxonomyIdentifier): Collection
    {
        return $this->taxonomyTerms
            ->filter(fn (TaxonomyTerm $term) => $term->parent?->identifier === $taxonomyIdentifier)
            ->values();
    }

    /**
     * Il termine di una tassonomia a scelta singola; se ce n'è più d'uno, il primo.
     */
    public function term(string $taxonomyIdentifier): ?TaxonomyTerm
    {
        return $this->terms($taxonomyIdentifier)->first();
    }
}
