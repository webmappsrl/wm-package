<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\EcTrack as EcTrackModel;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Nova\EcTrack;
use Wm\WmPackage\Nova\Fields\Choice\Choice;
use Wm\WmPackage\Services\IconSvgService;
use Wm\WmPackage\Support\TaxonomyTermOptions;

/*
 * Le opzioni dei termini di una tassonomia nascono in un posto solo, usato dal form delle
 * tassonomie e dal filtro dei termini (oc:8988). Il form deve ricevere esattamente le opzioni di
 * prima: il salvataggio scarta in silenzio i valori che non riconosce e stacca i termini che non
 * trova nella lista, quindi un formato cambiato cancellerebbe le classificazioni.
 */

uses(DatabaseTransactions::class);

beforeEach(function () {
    config(['wm-package.shard_name' => 'test_shard']);
    setIconCache(['txn-a' => [['d' => 'M0 0h10v10H0z']]]);
});

afterEach(fn () => setIconCache(null));

/**
 * La cache statica delle icone, al posto del file dello shard; null la svuota.
 */
function setIconCache(?array $pathsByName): void
{
    $ref = new ReflectionClass(IconSvgService::class);
    $ref->getProperty('pathsByName')->setValue(null, $pathsByName);
    $ref->getProperty('height')->setValue(null, $pathsByName === null ? null : 1024);
}

/**
 * Tre termini in ordine di `sort_order` diverso da quello di creazione: uno con icona, uno con
 * il solo nome inglese, uno senza nome in nessuna lingua.
 */
function optionsTaxonomy(): TaxonomyTerm
{
    $p = 'opt-'.uniqid();
    $taxonomy = TaxonomyTerm::create([
        'identifier' => $p.'-family',
        'name' => ['it' => 'Famiglia'],
        'single_choice' => false,
    ]);

    TaxonomyTerm::create(['identifier' => $p.'-last', 'parent_id' => $taxonomy->id, 'name' => ['it' => 'Ultimo'], 'sort_order' => 3, 'icon' => 'txn-a']);
    TaxonomyTerm::create(['identifier' => $p.'-english', 'parent_id' => $taxonomy->id, 'name' => ['en' => 'English only'], 'sort_order' => 1]);
    TaxonomyTerm::create(['identifier' => $p.'-nameless', 'parent_id' => $taxonomy->id, 'name' => [], 'sort_order' => 2]);

    return $taxonomy->refresh();
}

/**
 * Le opzioni che il form delle tassonomie passa al campo `Choice`.
 */
function formOptions(TaxonomyTerm $taxonomy): array
{
    $field = collect((new EcTrack(new EcTrackModel))->makeTermFieldsForTaxonomies([$taxonomy->identifier]))
        ->first(fn ($field) => $field instanceof Choice);

    return $field->jsonSerialize()['options'];
}

it('il form riceve le opzioni con id in stringa, in ordine di sort_order, con etichetta e icona', function () {
    app()->setLocale('it');
    $taxonomy = optionsTaxonomy();
    [$english, $nameless, $last] = $taxonomy->children->all();

    $options = formOptions($taxonomy);

    expect(array_column($options, 'value'))->toBe([(string) $english->id, (string) $nameless->id, (string) $last->id])
        ->and(array_column($options, 'label'))->toBe(['English only', $nameless->identifier, 'Ultimo'])
        ->and($options[0])->not->toHaveKey('svg')
        ->and($options[2]['svg'])->toContain('M0 0h10v10H0z');
});

it('il form e TaxonomyTermOptions danno le stesse opzioni', function () {
    $taxonomy = optionsTaxonomy();

    expect(TaxonomyTermOptions::for($taxonomy))->toBe(formOptions($taxonomy));
});

it('con la chiave identifier il valore è l identifier del termine', function () {
    $taxonomy = optionsTaxonomy();

    expect(array_column(TaxonomyTermOptions::for($taxonomy, 'identifier'), 'value'))
        ->toBe($taxonomy->children->pluck('identifier')->all());
});
