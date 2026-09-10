<?php

use Illuminate\Support\Collection;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Menu\MenuSection;
use Wm\WmPackage\WmPackageServiceProvider;

/**
 * Il package accoda le proprie voci a una sezione che il consumer ha
 * dichiarato, invece di crearne una propria in fondo: e' cosi' che il
 * consumer decide dove la sezione compare nel menu.
 */
function inject(array $menuItems, string $section, array $items, string $icon = 'map'): array
{
    $provider = new WmPackageServiceProvider(app());

    $method = new ReflectionMethod($provider, 'injectMenuSectionItems');
    $method->setAccessible(true);

    return $method->invoke($provider, $menuItems, $section, $items, $icon);
}

it('accoda le voci alla sezione dichiarata dal consumer, lasciandola dov e', function () {
    $menu = [
        MenuSection::make('Prima', []),
        MenuSection::make('Catasto', [])->icon('map')->collapsedByDefault(),
        MenuSection::make('Ultima', []),
    ];

    $result = inject($menu, 'Catasto', [MenuItem::link('Codici', '/codici')]);

    expect($result)->toHaveCount(3)
        ->and($result[1]->name)->toBe('Catasto');
});

it('non butta via le voci che il consumer aveva gia messo nella sezione', function () {
    // Regressione: le voci di una MenuSection non sono un array ma una
    // MenuCollection. Un controllo `is_array()` le scartava in silenzio, e la
    // voce dichiarata dal consumer spariva nel momento in cui il package ci
    // appendeva le proprie — successo davvero con la documentazione API in
    // «Catasto», che dal menu era semplicemente sparita.
    $menu = [
        MenuSection::make('Catasto', [MenuItem::link('Documentazione API', '/docs')])->icon('map'),
    ];

    $result = inject($menu, 'Catasto', [MenuItem::link('Codici', '/codici')]);

    $labels = collect(itemsOf($result[0]))->map(fn ($i) => $i->name)->all();

    // Le voci del package prima, quelle del consumer in coda: le prime sono
    // il contenuto della sezione, le seconde aggiunte come i collegamenti
    // alla documentazione.
    expect($labels)->toBe(['Codici', 'Documentazione API']);
});

/**
 * Le voci di una sezione: `items` e' protetta e non e' un array.
 */
function itemsOf(MenuSection $section): array
{
    $p = new ReflectionProperty($section, 'items');
    $p->setAccessible(true);
    $v = $p->getValue($section);

    return $v instanceof Collection ? $v->all() : (array) $v;
}

it('conserva lo stato chiuso della sezione dichiarata', function () {
    // Regressione: la versione precedente riportava solo icona e
    // richiudibilita', e per giunta chiamava `collapsable($valore)` — che non
    // accetta argomenti — rendendo richiudibile anche una sezione che non lo
    // era. Lo stato iniziale scelto dal consumer andava perduto a ogni
    // iniezione.
    $menu = [MenuSection::make('Catasto', [])->icon('map')->collapsedByDefault()];

    $result = inject($menu, 'Catasto', [MenuItem::link('Codici', '/codici')]);

    expect($result[0]->collapsable)->toBeTrue()
        ->and($result[0]->collapsedByDefault)->toBeTrue()
        ->and($result[0]->icon)->toBe('map');
});

it('non rende richiudibile una sezione che il consumer ha lasciato aperta', function () {
    $menu = [MenuSection::make('Catasto', [])->icon('map')];

    $result = inject($menu, 'Catasto', [MenuItem::link('Codici', '/codici')]);

    expect($result[0]->collapsable)->toBeFalse()
        ->and($result[0]->collapsedByDefault)->toBeFalse();
});

it('crea la sezione in fondo se il consumer non l ha dichiarata', function () {
    $menu = [MenuSection::make('Prima', [])];

    $result = inject($menu, 'Catasto', [MenuItem::link('Codici', '/codici')]);

    expect($result)->toHaveCount(2)
        ->and($result[1]->name)->toBe('Catasto')
        ->and($result[1]->collapsedByDefault)->toBeTrue();
});
