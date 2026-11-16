<?php

use Wm\WmPackage\TrailRegistry\Anomalies\TrailRegistryAnomalyTypes;
use Wm\WmPackage\TrailRegistry\Inconsistencies\InconsistencyTypeDefinition;
use Wm\WmPackage\TrailRegistry\Inconsistencies\TrailRegistryInconsistencyTypes;

/**
 * Le incongruenze catastali (oc:8989): i casi in cui due informazioni che il
 * catasto pubblica sullo stesso sentiero non reggono insieme. Famiglia distinta
 * dalle anomalie, che riguardano il codice e si correggono alla fonte.
 */
class FakeInconsistencyType implements InconsistencyTypeDefinition
{
    public function label(): string
    {
        return 'Sentiero validato senza percorribilità';
    }

    public function description(): string
    {
        return 'Il catasto dichiara il sentiero validato, ma nessuno ha detto se si cammina.';
    }
}

beforeEach(function () {
    config(['wm-package.features.trail_registry.inconsistency_types' => []]);
});

it('non conosce nessun tipo finché uno shard non ne dichiara', function () {
    expect(TrailRegistryInconsistencyTypes::values())->toBe([])
        ->and(TrailRegistryInconsistencyTypes::isKnown('missing_walkability'))->toBeFalse();
});

it('riconosce un tipo dichiarato dallo shard', function () {
    config(['wm-package.features.trail_registry.inconsistency_types' => [
        'missing_walkability' => FakeInconsistencyType::class,
    ]]);

    expect(TrailRegistryInconsistencyTypes::values())->toBe(['missing_walkability'])
        ->and(TrailRegistryInconsistencyTypes::isKnown('missing_walkability'))->toBeTrue()
        ->and(TrailRegistryInconsistencyTypes::label('missing_walkability'))
        ->toBe('Sentiero validato senza percorribilità');
});

it('restituisce la chiave quando il tipo non è dichiarato, invece di esplodere', function () {
    expect(TrailRegistryInconsistencyTypes::label('tipo_che_non_esiste'))->toBe('tipo_che_non_esiste')
        ->and(TrailRegistryInconsistencyTypes::definition('tipo_che_non_esiste'))->toBeNull();
});

it('tiene i tipi separati da quelli delle anomalie', function () {
    config([
        'wm-package.features.trail_registry.inconsistency_types' => ['missing_walkability' => FakeInconsistencyType::class],
        'wm-package.features.trail_registry.anomaly_types' => [],
    ]);

    expect(TrailRegistryAnomalyTypes::isKnown('missing_walkability'))->toBeFalse();
});
