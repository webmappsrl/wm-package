<?php

declare(strict_types=1);

use Wm\WmPackage\Http\Controllers\Api\ElasticsearchController;

it('su un indice misto mette il nome in tutte le lingue solo dove c\'è e toglie sempre name_translations', function () {
    // Come su uno shard dopo il bump: solo le tracce già reindicizzate hanno name_translations,
    // le altre mantengono il name di oggi (oc:8681).
    $results = [
        'hits' => [
            ['id' => 1, 'name' => 'V6 – Anello di Vecchiano',
                'name_translations' => ['it' => 'V6 – Anello di Vecchiano', 'en' => 'V6 - Loop of Vecchiano']],
            ['id' => 2, 'name' => 'Percorso 2 – Circuit Haut Estéron'],
            ['id' => 3, 'name' => 'Boucle 1', 'name_translations' => []],
            ['id' => 4, 'name' => 'Senza traduzioni', 'name_translations' => null],
            ['id' => 5, 'name' => 'Documento anomalo', 'name_translations' => 'stringa'],
        ],
        'aggregations' => ['themes' => ['doc_count' => 5]],
    ];

    expect(ElasticsearchController::localizeSearchResults($results))->toBe([
        'hits' => [
            ['id' => 1, 'name' => ['it' => 'V6 – Anello di Vecchiano', 'en' => 'V6 - Loop of Vecchiano']],
            ['id' => 2, 'name' => 'Percorso 2 – Circuit Haut Estéron'],
            ['id' => 3, 'name' => 'Boucle 1'],
            ['id' => 4, 'name' => 'Senza traduzioni'],
            ['id' => 5, 'name' => 'Documento anomalo'],
        ],
        'aggregations' => ['themes' => ['doc_count' => 5]],
    ]);
});

it('lascia invariata una risposta senza risultati', function () {
    expect(ElasticsearchController::localizeSearchResults(['hits' => []]))->toBe(['hits' => []]);
});

it('non aggiunge hits a una risposta che non li ha', function () {
    $results = ['aggregations' => ['themes' => ['doc_count' => 0]]];

    expect(ElasticsearchController::localizeSearchResults($results))->toBe($results);
});
