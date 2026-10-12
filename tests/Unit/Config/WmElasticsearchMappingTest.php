<?php

declare(strict_types=1);

use Wm\WmPackage\Models\EcTrack;

it('dichiara name_translations come oggetto ricercabile, uguale agli indici esistenti', function () {
    // Senza enabled: false le lingue nascono come negli indici già esistenti, dove il campo è creato
    // da Elastic al primo documento (oc:8681).
    $field = config('wm-elasticsearch.indices.mappings.default.properties.'.EcTrack::SEARCH_NAME_TRANSLATIONS_FIELD);

    expect($field)->toBe(['type' => 'object'])
        ->and($field)->not->toHaveKey('enabled');
});

it('lascia name come testo, su cui si ordina', function () {
    expect(config('wm-elasticsearch.indices.mappings.default.properties.name.type'))->toBe('text')
        ->and(config('wm-elasticsearch.indices.mappings.default.properties.name.fields.keyword.type'))->toBe('keyword');
});
