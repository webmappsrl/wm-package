<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\TaxonomyWhere\FetchTaxonomyWhereGeometryJob;
use Wm\WmPackage\Models\TaxonomyWhere;

uses(TestCase::class, DatabaseTransactions::class);

/**
 * Il dev ha chiesto durante il test manuale di oc:8588 che syncNameFromDetail()
 * corregga il segnaposto (id OSM salvato al posto del nome) su OGNI traduzione,
 * non solo su 'it' come faceva finora.
 *
 * In un round successivo la regola e' stata estesa: il dettaglio sostituisce sempre le 5
 * traduzioni delle lingue della piattaforma, anche quando la where ha gia' un nome
 * "affidabile" — la guardia di uscita anticipata e' stata rimossa. Il terzo test qui sotto
 * e' stato aggiornato di conseguenza; la copertura completa della nuova regola sta in
 * FetchTaxonomyWhereGeometryJobNameTranslationsTest.php.
 */
beforeEach(function () {
    config()->set('wm-package.clients.osmfeatures.host', 'https://osmfeatures.test');
});

it('replaces the placeholder id on every translation, not just it', function () {
    Http::fake([
        '*/admin-areas/R1' => Http::response([
            'type' => 'Feature',
            'properties' => ['name' => 'Nome Vero', 'admin_level' => 8],
            'geometry' => null,
        ]),
    ]);

    $where = TaxonomyWhere::create([
        'name' => ['en' => 'R1', 'it' => 'R1'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R1'],
    ]);

    (new FetchTaxonomyWhereGeometryJob($where->id))->handle(app(OsmfeaturesClient::class));

    $fresh = $where->fresh();
    expect($fresh->getTranslation('name', 'en'))->toBe('Nome Vero');
    expect($fresh->getTranslation('name', 'it'))->toBe('Nome Vero');
});

it('sets it too when only en carries the placeholder', function () {
    Http::fake([
        '*/admin-areas/R1' => Http::response([
            'type' => 'Feature',
            'properties' => ['name' => 'Nome Vero', 'admin_level' => 8],
            'geometry' => null,
        ]),
    ]);

    $where = TaxonomyWhere::create([
        'name' => ['en' => 'R1'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R1'],
    ]);

    (new FetchTaxonomyWhereGeometryJob($where->id))->handle(app(OsmfeaturesClient::class));

    $fresh = $where->fresh();
    expect($fresh->getTranslation('name', 'en'))->toBe('Nome Vero');
    expect($fresh->getTranslation('name', 'it'))->toBe('Nome Vero');
});

it('replaces even an already good name, because the detail is the more complete source', function () {
    Http::fake([
        '*/admin-areas/R1' => Http::response([
            'type' => 'Feature',
            'properties' => ['name' => 'Nome Vero (OSM)', 'admin_level' => 8],
            'geometry' => null,
        ]),
    ]);

    $where = TaxonomyWhere::create([
        'name' => ['en' => 'Povoletto'],
        'properties' => ['source' => 'osmfeatures', 'osmfeatures_id' => 'R1'],
    ]);

    (new FetchTaxonomyWhereGeometryJob($where->id))->handle(app(OsmfeaturesClient::class));

    $fresh = $where->fresh();
    expect($fresh->getTranslations('name'))->toEqual([
        'it' => 'Nome Vero (OSM)',
        'en' => 'Nome Vero (OSM)',
        'de' => 'Nome Vero (OSM)',
        'fr' => 'Nome Vero (OSM)',
        'es' => 'Nome Vero (OSM)',
    ]);
});
