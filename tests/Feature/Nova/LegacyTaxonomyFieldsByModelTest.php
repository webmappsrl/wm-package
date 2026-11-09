<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyPoiType;

// Si lancia dalla suite del package (`cd wm-package && vendor/bin/pest`): lo shard non registra
// l'autoload di `Wm\WmPackage\Tests` (docs/knowledge/testare-il-package.md).
uses(DatabaseTransactions::class);

/**
 * I Tipi POI comparivano anche sulle tracce (oc:8932): la relazione `taxonomyPoiTypes()` sta nel
 * trait `TaxonomyAbleModel`, quindi `method_exists` è vero su ogni modello EC. A dire su quali
 * contenuti vale una tassonomia storica è la chiave `models` della config.
 */
beforeEach(function () {
    TaxonomyPoiType::query()->create([
        'identifier' => 'poi-type-test-'.uniqid(),
        'name' => ['it' => 'Tipo di prova'],
    ]);
});

it('mostra i Tipi POI sulla Resource dei punti di interesse', function () {
    expect(legacyTaxonomyFieldNames(new Wm\WmPackage\Nova\EcPoi(new EcPoi)))
        ->toContain(__('Poi Types'));
});

it('non mostra i Tipi POI sulla Resource dei percorsi', function () {
    expect(legacyTaxonomyFieldNames(new Wm\WmPackage\Nova\EcTrack(new EcTrack)))
        ->not->toContain(__('Poi Types'));
});

it('mostra le Attività su entrambe le Resource, perché non dichiarano modelli', function () {
    expect(legacyTaxonomyFieldNames(new Wm\WmPackage\Nova\EcPoi(new EcPoi)))->toContain(__('Activities'))
        ->and(legacyTaxonomyFieldNames(new Wm\WmPackage\Nova\EcTrack(new EcTrack)))->toContain(__('Activities'));
});

/**
 * @return array<int, string>
 */
function legacyTaxonomyFieldNames(object $resource): array
{
    $method = new ReflectionMethod($resource, 'makeLegacyTaxonomyFieldsByTab');
    $method->setAccessible(true);

    $names = [];
    foreach ($method->invoke($resource) as $fields) {
        foreach ($fields as $field) {
            $names[] = $field->name;
        }
    }

    return $names;
}
