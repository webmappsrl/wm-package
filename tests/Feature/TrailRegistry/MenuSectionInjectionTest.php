<?php

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
