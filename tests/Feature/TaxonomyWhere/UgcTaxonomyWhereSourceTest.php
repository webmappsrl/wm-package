<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\User;

uses(TestCase::class, DatabaseTransactions::class);

it('adds the osmfeatures source to UGC taxonomy_where', function () {
    // UgcPoi::factory() risolve user/app con User::first()/App::first(): in una transazione di
    // test senza fixture pre-esistenti tornerebbero null, quindi li creiamo esplicitamente
    // (stesso pattern di UgcControllerTaxonomyWhereAsyncFallbackTest.php).
    $user = User::factory()->create();
    $app = App::factory()->create();

    $ugc = UgcPoi::factory()->create([
        'user_id' => $user->id,
        'app_id' => $app->id,
    ]);

    $client = Mockery::mock(OsmfeaturesClient::class);
    $client->shouldReceive('getWheresByGeojson')->andReturn([
        'R40784' => ['it' => 'Lazio', 'en' => 'Lazio', '_admin_level' => 4],
    ]);

    (new UpdateModelWithGeometryTaxonomyWhere($ugc))->handle($client);

    expect($ugc->fresh()->properties['taxonomy_where']['R40784'])
        ->toEqual(['it' => 'Lazio', 'en' => 'Lazio', '_admin_level' => 4, '_source' => 'osmfeatures']);
});

it('leaves the existing taxonomy_where untouched when osmfeatures returns only wheres without a valid name (oc:8588)', function () {
    $user = User::factory()->create();
    $app = App::factory()->create();

    $existing = ['R1' => ['it' => 'Toscana', 'en' => 'Toscana', '_admin_level' => 4, '_source' => 'osmfeatures']];

    $ugc = UgcPoi::factory()->create([
        'user_id' => $user->id,
        'app_id' => $app->id,
        'properties' => ['taxonomy_where' => $existing],
    ]);

    // getWheresByGeojson() torna risultati non vuoti, ma senza alcun nome valido: dopo
    // fromOsmfeatures() (che scarta le voci senza nome, stesso comportamento di
    // TaxonomyWhereDisplayService::normalize()) il mappato e' vuoto. Il controllo "nessun
    // risultato" deve girare DOPO fromOsmfeatures(), non su $wheres grezzo, altrimenti il
    // valore esistente viene azzerato.
    $client = Mockery::mock(OsmfeaturesClient::class);
    $client->shouldReceive('getWheresByGeojson')->andReturn([
        'R2' => ['it' => '', 'en' => ''],
    ]);

    (new UpdateModelWithGeometryTaxonomyWhere($ugc))->handle($client);

    expect($ugc->fresh()->properties['taxonomy_where'])->toEqual($existing);
});
