<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Nova\Filters\TaxonomyTermFilter;

/*
 * Il filtro sui termini di una tassonomia (oc:8988): opzioni dai termini figli, OR fra i valori
 * dello stesso filtro, AND fra filtri, mai un risultato più largo per valori non validi.
 */

uses(DatabaseTransactions::class);

beforeEach(fn () => config(['wm-package.shard_name' => 'test_shard']));

/**
 * Una tassonomia con i suoi termini, identifier con prefisso unico (regola dei test del package).
 *
 * @param  array<string, int>  $terms  suffisso => sort_order
 */
function filterTaxonomy(string $prefix, array $terms, array $name = ['it' => 'Tipo', 'en' => 'Type']): TaxonomyTerm
{
    $taxonomy = TaxonomyTerm::create(['identifier' => $prefix, 'name' => $name]);
    foreach ($terms as $suffix => $sortOrder) {
        TaxonomyTerm::create([
            'identifier' => "{$prefix}-{$suffix}",
            'parent_id' => $taxonomy->id,
            'name' => ['it' => ucfirst($suffix)],
            'sort_order' => $sortOrder,
        ]);
    }

    return $taxonomy;
}

function filterTrackWithTerms(string ...$identifiers): EcTrack
{
    $track = EcTrack::factory()->createQuietly(['osmid' => null]);
    $track->taxonomyTerms()->attach(TaxonomyTerm::whereIn('identifier', $identifiers)->pluck('id'));

    return $track;
}

/**
 * Gli id delle tracce che restano dopo i filtri, ognuno con il suo valore.
 *
 * @param  list<array{0: TaxonomyTermFilter, 1: mixed}>  $filters
 */
function filteredTrackIds(array $filters, array $among): array
{
    $query = EcTrack::query()->whereIn('id', $among);
    foreach ($filters as [$filter, $value]) {
        $query = $filter->apply(NovaRequest::create('/'), $query, $value);
    }

    return $query->orderBy('id')->pluck('id')->all();
}

it('propone i termini figli in ordine di sort_order, con l identifier come valore', function () {
    $p = 'tf-'.uniqid();
    filterTaxonomy($p, ['cammino' => 2, 'sentiero' => 1]);

    $options = (new TaxonomyTermFilter($p))->options(NovaRequest::create('/'));

    expect(array_column($options, 'value'))->toBe(["{$p}-sentiero", "{$p}-cammino"])
        ->and(array_column($options, 'label'))->toBe(['Sentiero', 'Cammino']);
});

it('in single restituisce le tracce con quel termine', function () {
    $p = 'tf-'.uniqid();
    filterTaxonomy($p, ['sentiero' => 1, 'cammino' => 2]);
    $sentiero = filterTrackWithTerms("{$p}-sentiero");
    $cammino = filterTrackWithTerms("{$p}-cammino");
    $senza = filterTrackWithTerms();

    expect(filteredTrackIds([[new TaxonomyTermFilter($p), "{$p}-sentiero"]], [$sentiero->id, $cammino->id, $senza->id]))
        ->toBe([$sentiero->id]);
});

it('in multiple i valori dello stesso filtro valgono in OR', function () {
    $p = 'tf-'.uniqid();
    filterTaxonomy($p, ['sentiero' => 1, 'cammino' => 2, 'itinerario' => 3]);
    $sentiero = filterTrackWithTerms("{$p}-sentiero");
    $cammino = filterTrackWithTerms("{$p}-cammino");
    $itinerario = filterTrackWithTerms("{$p}-itinerario");

    $ids = filteredTrackIds(
        [[new TaxonomyTermFilter($p, multiple: true), ["{$p}-sentiero", "{$p}-cammino"]]],
        [$sentiero->id, $cammino->id, $itinerario->id],
    );

    expect($ids)->toBe([$sentiero->id, $cammino->id]);
});

it('due filtri su tassonomie diverse si combinano in AND e hanno chiavi diverse', function () {
    $tipo = 'tf-'.uniqid();
    $difficolta = 'tf-'.uniqid();
    filterTaxonomy($tipo, ['sentiero' => 1]);
    filterTaxonomy($difficolta, ['at' => 1, 'ae' => 2]);
    $entrambi = filterTrackWithTerms("{$tipo}-sentiero", "{$difficolta}-at");
    $soloTipo = filterTrackWithTerms("{$tipo}-sentiero", "{$difficolta}-ae");
    $tipoFilter = new TaxonomyTermFilter($tipo);
    $difficoltaFilter = new TaxonomyTermFilter($difficolta);

    $ids = filteredTrackIds(
        [[$tipoFilter, "{$tipo}-sentiero"], [$difficoltaFilter, "{$difficolta}-at"]],
        [$entrambi->id, $soloTipo->id],
    );

    expect($ids)->toBe([$entrambi->id])
        ->and($tipoFilter->key())->not->toBe($difficoltaFilter->key());
});

it('una tassonomia inesistente non ha opzioni e non dà errori', function () {
    $filter = new TaxonomyTermFilter('tf-inesistente-'.uniqid());

    expect($filter->options(NovaRequest::create('/')))->toBe([])
        ->and(fn () => $filter->jsonSerialize())->not->toThrow(Throwable::class);
});

it('ignora l identifier di un termine di un altra tassonomia', function () {
    $p = 'tf-'.uniqid();
    $altra = 'tf-'.uniqid();
    filterTaxonomy($p, ['sentiero' => 1]);
    filterTaxonomy($altra, ['estraneo' => 1]);
    $sentiero = filterTrackWithTerms("{$p}-sentiero");
    $estraneo = filterTrackWithTerms("{$altra}-estraneo");

    $ids = filteredTrackIds(
        [[new TaxonomyTermFilter($p, multiple: true), ["{$p}-sentiero", "{$altra}-estraneo"]]],
        [$sentiero->id, $estraneo->id],
    );

    expect($ids)->toBe([$sentiero->id]);
});

it('con soli valori non validi il risultato è vuoto, non tutte le tracce', function () {
    $p = 'tf-'.uniqid();
    filterTaxonomy($p, ['sentiero' => 1]);
    $sentiero = filterTrackWithTerms("{$p}-sentiero");
    $senza = filterTrackWithTerms();

    $ids = filteredTrackIds(
        [[new TaxonomyTermFilter($p, multiple: true), ["{$p}-ritirato"]]],
        [$sentiero->id, $senza->id],
    );

    expect($ids)->toBe([]);
});

it('il titolo è il nome della tassonomia nella lingua corrente, o l identifier', function () {
    $p = 'tf-'.uniqid();
    filterTaxonomy($p, [], ['it' => 'Tipo percorso', 'en' => 'Route type']);
    $missing = 'tf-inesistente-'.uniqid();

    app()->setLocale('it');
    $it = (new TaxonomyTermFilter($p))->name();
    app()->setLocale('en');
    $en = (new TaxonomyTermFilter($p))->name();

    expect($it)->toBe('Tipo percorso')
        ->and($en)->toBe('Route type')
        ->and((new TaxonomyTermFilter($missing))->name())->toBe($missing);
});

it('il valore di partenza segue la modalità', function () {
    $p = 'tf-'.uniqid();

    expect((new TaxonomyTermFilter($p))->default())->toBe('')
        ->and((new TaxonomyTermFilter($p, multiple: true))->default())->toBe([]);
});
