<?php

use Wm\WmPackage\Services\Import\DataTransformer;

// oc:8679: related_url arriva da Geohub come testo (oggetto JSON, "[]", "false"...) e va
// salvato come oggetto etichetta → url, oppure non salvato quando non contiene link.

it('converte related_url in etichetta → url, o null quando non ci sono link', function ($input, $expected) {
    expect((new DataTransformer)->relatedUrlToArray($input))->toBe($expected);
})->with([
    'oggetto JSON' => ['{"Ufficio turistico":"https://www.comune.levanto.sp.it/"}', ['Ufficio turistico' => 'https://www.comune.levanto.sp.it/']],
    'false come testo' => ['false', null],
    'lista vuota come testo' => ['[]', null],
    'oggetto vuoto come testo' => ['{}', null],
    'null' => [null, null],
    'stringa vuota' => ['', null],
    'indirizzo semplice con spazi' => ['  https://www.esempio.it  ', ['https://www.esempio.it' => 'https://www.esempio.it']],
    'valori non testuali scartati' => ['{"Sito": false, "Info": "https://a.it"}', ['Info' => 'https://a.it']],
    'JSON non valido' => ['{"rotto', null],
    'testo senza http' => ['www.comune.it', null],
]);

it('mappa related_url di POI e tracce con relatedUrlToArray', function (string $model) {
    expect(config("wm-geohub-import.import_mapping.{$model}.properties.mapping.related_url"))
        ->toBe(['field' => 'related_url', 'transformer' => [DataTransformer::class, 'relatedUrlToArray']]);
})->with(['ec_poi', 'ec_track']);
