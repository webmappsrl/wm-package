<?php

use Illuminate\Http\Request;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;

/**
 * Presidia l'iniezione della sezione di menu "Catasto"
 * (WmPackageServiceProvider::addWmpackageToolsMenuItem(), via
 * injectMenuSectionItems()): deve comparire SOLO a dominio acceso.
 *
 * Nova::$mainMenuCallback e' popolato in risposta all'evento ServingNova
 * (Nova::serving() nel boot del provider si limita a registrare un
 * listener): a NovaServiceProvider non registrato in TestCase, il modo per
 * costruire davvero il menu qui e' dispatchare l'evento a mano e poi invocare
 * il callback risultante.
 *
 * Nova::$mainMenuCallback e' una proprieta' statica del package Nova, non
 * legata al container: sopravvive al riavvio dell'app fra un test e l'altro.
 * Ogni test la azzera prima di dispatchare l'evento, altrimenti il secondo
 * test troverebbe il callback del primo gia' impostato e lo riavvolgerebbe
 * invece di ricostruirlo da zero.
 */
function buildMainMenu(): array
{
    // Simula il caso reale: quando Nova serve una richiesta ha gia' un suo
    // mainMenuCallback (quello di default, o quello di un altro package).
    // addWmpackageToolsMenuItem() lo intercetta e lo riavvolge via
    // injectMenuSectionItems() — e' il ramo che questo test vuole
    // esercitare, non quello (piu' semplice, e in produzione irraggiungibile
    // dopo il boot di Nova) preso quando non c'e' nessun callback originale.
    Nova::$mainMenuCallback = fn (Request $request) => [];

    ServingNova::dispatch(app(), Request::create('/'));

    expect(Nova::$mainMenuCallback)->not->toBeNull();

    return call_user_func(Nova::$mainMenuCallback, Request::create('/'));
}

function findMenuSection(array $menu, string $name): ?MenuSection
{
    foreach ($menu as $section) {
        if ($section instanceof MenuSection && (string) $section->name === $name) {
            return $section;
        }
    }

    return null;
}

it('a dominio spento non mette alcuna sezione Catasto nel menu', function () {
    config(['wm-package.features.trail_registry.enabled' => false]);

    $menu = buildMainMenu();

    expect(findMenuSection($menu, __('Catasto')))->toBeNull();
});

it('a dominio acceso mette la sezione Catasto con le sue voci', function () {
    config(['wm-package.features.trail_registry.enabled' => true]);

    $menu = buildMainMenu();

    $catasto = findMenuSection($menu, __('Catasto'));

    expect($catasto)->not->toBeNull();

    $labels = collect($catasto->items)->map(fn ($item) => (string) $item->name)->all();

    expect($labels)->toEqualCanonicalizing([__('Istanze'), __('Registro dei codici'), __('Anomalie')]);
});

it('non rompe la sezione Tools gia iniettata dallo stesso meccanismo', function () {
    config(['wm-package.features.trail_registry.enabled' => false]);
    $menuOff = buildMainMenu();

    config(['wm-package.features.trail_registry.enabled' => true]);
    $menuOn = buildMainMenu();

    $toolsOff = findMenuSection($menuOff, __('Tools'));
    $toolsOn = findMenuSection($menuOn, __('Tools'));

    expect($toolsOff)->not->toBeNull();
    expect($toolsOn)->not->toBeNull();

    // Le stesse voci (a meno dell'ordine, irrilevante qui), a dominio
    // spento o acceso: l'iniezione della sezione Catasto non deve
    // alterare cosa la sezione Tools contiene.
    $labelsOff = collect($toolsOff->items)->map(fn ($item) => (string) $item->name)->all();
    $labelsOn = collect($toolsOn->items)->map(fn ($item) => (string) $item->name)->all();

    expect($labelsOn)->toEqualCanonicalizing($labelsOff);
});
