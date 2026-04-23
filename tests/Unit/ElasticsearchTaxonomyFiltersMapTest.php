<?php

declare(strict_types=1);

use Wm\WmPackage\Http\Controllers\Api\ElasticsearchController;

it('espone la mappa taxonomy -> campo indicizzato incluso layers', function () {
    $map = ElasticsearchController::taxonomyFiltersMap();

    expect($map)->toHaveKey('layers')
        ->and($map['layers'])->toBe('layers')
        ->and($map['wheres'])->toBe('taxonomyWheres')
        ->and($map['activities'])->toBe('taxonomyActivities');

    $field = $map['layers'];
    $identifier = '8';
    if ($field === 'layers') {
        $identifier = (int) $identifier;
    }
    expect($identifier)->toBe(8);
});
