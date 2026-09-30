<?php

declare(strict_types=1);

use Wm\WmPackage\Dto\EcTrackPropertiesData;

it('omette via quando è nulla, così un import non cancella quella salvata', function () {
    expect((new EcTrackPropertiesData(from: 'A', to: 'B'))->toArray())
        ->toBe(['from' => 'A', 'to' => 'B']);
});

it('emette via quando è valorizzata', function () {
    expect((new EcTrackPropertiesData(from: 'A', to: 'B', via: 'Iscacari'))->toArray())
        ->toMatchArray(['from' => 'A', 'via' => 'Iscacari', 'to' => 'B']);
});

it('via è l\'ultimo parametro: gli argomenti posizionali esistenti non si spostano', function () {
    $dto = new EcTrackPropertiesData(null, null, null, null, null, 'A', 'B', 'REF');

    expect($dto->from)->toBe('A')->and($dto->to)->toBe('B')->and($dto->ref)->toBe('REF')->and($dto->via)->toBeNull();
});
