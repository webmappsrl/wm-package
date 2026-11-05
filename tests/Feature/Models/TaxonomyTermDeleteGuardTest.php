<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * Nessuna strada deve lasciare un termine senza la sua tassonomia (oc:8910).
 *
 * `taxonomy_terms` è auto-referenziale: una riga senza `parent_id` è una tassonomia. Con
 * `nullOnDelete` cancellare una tassonomia non cancellava i suoi termini, li promuoveva: i tre
 * termini di «Tipo percorso» diventavano tre tassonomie, e le loro relazioni restavano con la
 * copia di una tassonomia che non esisteva più — invisibili ai filtri, che raggruppano proprio
 * per quella copia. La stessa corruzione si otteneva senza cancellare niente, svuotando il campo
 * «Tassonomia» di un termine dalla sua scheda.
 */
uses(DatabaseTransactions::class);

function taxonomyWithTerms(): array
{
    $suffix = uniqid();
    $taxonomy = TaxonomyTerm::create([
        'identifier' => "route-type-{$suffix}",
        'name' => ['it' => 'Tipo percorso'],
    ]);
    $trail = TaxonomyTerm::create([
        'parent_id' => $taxonomy->id,
        'identifier' => "route-type-trail-{$suffix}",
        'name' => ['it' => 'Sentiero'],
    ]);

    return [$taxonomy, $trail];
}

it('rifiuta di togliere la tassonomia a un termine collegato a dei contenuti (oc:8910)', function () {
    // La seconda porta: stessa corruzione, ma con un salvataggio invece di una cancellazione.
    [$taxonomy, $trail] = taxonomyWithTerms();
    $app = App::factory()->createQuietly();
    $track = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
    $track->taxonomyTerms()->attach($trail->id);

    $trail->parent_id = null;

    expect(fn () => $trail->save())->toThrow(InvalidArgumentException::class);
    expect(TaxonomyTerm::whereKey($trail->id)->value('parent_id'))->toBe($taxonomy->id)
        ->and(DB::table('taxonomy_termables')->where('taxonomy_term_id', $trail->id)->value('parent_id'))
        ->toBe($taxonomy->id);
});

it('lascia togliere la tassonomia a un termine che non è collegato a niente (oc:8910)', function () {
    // Un termine appena creato sotto la tassonomia sbagliata si deve poter correggere.
    [, $trail] = taxonomyWithTerms();

    $trail->parent_id = null;
    $trail->save();

    expect(TaxonomyTerm::whereKey($trail->id)->value('parent_id'))->toBeNull();
});
