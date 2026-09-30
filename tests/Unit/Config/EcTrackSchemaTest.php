<?php

declare(strict_types=1);

it('lo schema ha via fra from e to, testo non traducibile', function () {
    $names = array_column(config('wm-ec-track-schema.properties.fields'), 'name');
    $via = collect(config('wm-ec-track-schema.properties.fields'))->firstWhere('name', 'via');

    expect(array_search('via', $names, true))->toBe(array_search('from', $names, true) + 1)
        ->and(array_search('to', $names, true))->toBe(array_search('via', $names, true) + 1)
        ->and($via['type'])->toBe('text')
        ->and($via['translatable'])->toBeFalse()
        ->and($via['label'])->toBe(['it' => 'meta intermedia', 'en' => 'Via']);
});
