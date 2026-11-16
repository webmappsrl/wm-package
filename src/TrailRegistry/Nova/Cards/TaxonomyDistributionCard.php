<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Cards;

use Illuminate\Support\Facades\DB;
use Laravel\Nova\Card;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Support\TaxonomyTermLabel;

/**
 * Quanti sentieri ci sono per ogni termine di una famiglia (oc:8989).
 *
 * E' la forma generale di cui la percorribilita' e' un caso: la stessa domanda
 * vale per la validazione, e per qualsiasi altra classificazione a scelta
 * singola che uno shard voglia guardare d'insieme.
 *
 * Gli stati si leggono dal database, mai da un elenco nel codice: su osm2cai le
 * metriche confrontano le stringhe dell'enum, ed e' li' che un `break`
 * dimenticato conta da anni i non percorribili anche fra i parzialmente
 * percorribili senza che nessuno se ne accorga.
 */
class TaxonomyDistributionCard extends Card
{
    public $component = 'trail-registry-taxonomy-distribution-card';

    public $width = '1/3';

    /**
     * @param  string  $familyIdentifier  La famiglia da contare.
     * @param  string  $title  Il titolo della card, gia' tradotto.
     * @param  string|null  $excludedIdentifier  Un termine da tenere fuori dall'elenco e contare
     *                                           a parte: serve allo stato «non si sa», che finche'
     *                                           il dato non e' a regime e' la maggioranza e
     *                                           schiaccerebbe la composizione di quelli che un
     *                                           dato ce l'hanno.
     * @param  string|null  $excludedNote  La frase che accompagna quel numero, con `:count` e
     *                                     `:total` al posto dei due valori.
     * @param  list<string>|null  $onlyIdentifiers  Se dato, i soli termini da mostrare. Serve a
     *                                              una famiglia che in tabella ha anche righe
     *                                              residue di una configurazione precedente: un
     *                                              termine che la storia nomina non si puo'
     *                                              cancellare — le chiavi esterne degli eventi
     *                                              sono senza cascata, ed e' voluto — ma non deve
     *                                              comparire a zero fra gli stati di oggi.
     */
    public function __construct(
        protected string $familyIdentifier,
        protected string $title,
        protected ?string $excludedIdentifier = null,
        protected ?string $excludedNote = null,
        protected ?array $onlyIdentifiers = null,
    ) {
        parent::__construct();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $family = TaxonomyTerm::query()
            ->where('identifier', $this->familyIdentifier)
            ->whereNull('parent_id')
            ->first();

        if ($family === null) {
            return array_merge(parent::jsonSerialize(), [
                'title' => $this->title,
                'slices' => [],
                'excludedCount' => 0,
                'excludedNote' => null,
            ]);
        }

        $counts = $this->countsByTerm($family->id);
        $terms = $family->children()->orderBy('sort_order')->orderBy('id')->get();

        $excluded = $this->excludedIdentifier === null
            ? null
            : $terms->firstWhere('identifier', $this->excludedIdentifier);

        $slices = $terms
            ->reject(fn (TaxonomyTerm $term) => $excluded !== null && $term->id === $excluded->id)
            ->filter(fn (TaxonomyTerm $term) => $this->onlyIdentifiers === null
                || in_array($term->identifier, $this->onlyIdentifiers, true))
            ->map(fn (TaxonomyTerm $term) => [
                'identifier' => $term->identifier,
                'label' => TaxonomyTermLabel::for($term),
                'count' => $counts[$term->id] ?? 0,
            ])
            ->values()
            ->all();

        return array_merge(parent::jsonSerialize(), [
            'title' => $this->title,
            'slices' => $slices,
            'excludedCount' => $excluded === null ? 0 : ($counts[$excluded->id] ?? 0),
            'excludedNote' => $excluded === null ? null : $this->excludedNote,
        ]);
    }

    /** @return array<int, int> id del termine => quanti sentieri */
    protected function countsByTerm(int $familyId): array
    {
        /** @var array<int, int> $counts */
        $counts = DB::table('taxonomy_termables')
            ->join('taxonomy_terms', 'taxonomy_terms.id', '=', 'taxonomy_termables.taxonomy_term_id')
            ->where('taxonomy_terms.parent_id', $familyId)
            ->where('taxonomy_termables.termable_type', 'like', '%EcTrack')
            ->groupBy('taxonomy_terms.id')
            // L'alias serve: senza, `pluck` cerca una colonna «count(*)».
            ->selectRaw('taxonomy_terms.id as term_id, count(*) as total')
            ->pluck('total', 'term_id')
            ->map(fn ($count) => (int) $count)
            ->all();

        return $counts;
    }
}
