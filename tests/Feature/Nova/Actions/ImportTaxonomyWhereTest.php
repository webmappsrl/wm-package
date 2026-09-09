<?php

namespace Wm\WmPackage\Tests\Feature\Nova\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\TaxonomyWhere\FetchTaxonomyWhereGeometryJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Nova\Actions\ImportTaxonomyWhere;

class ImportTaxonomyWhereTest extends TestCase
{
    use DatabaseTransactions;

    public function test_osmfeatures_source_creates_taxonomy_where_for_single_app(): void
    {
        Bus::fake();

        $app = App::factory()->create(['map_bbox' => json_encode([10, 40, 11, 41])]);

        $client = \Mockery::mock(OsmfeaturesClient::class);
        $client->shouldReceive('getAdminAreasIds')
            ->once()
            ->andReturn([
                ['id' => 'R123', 'name' => 'Toscana', 'updated_at' => now()->toIso8601String()],
            ]);
        $this->app->instance(OsmfeaturesClient::class, $client);

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'osmfeatures_4', 'app_id' => $app->id]), collect());

        $response = $action->handle($fields, collect());

        $this->assertStringContainsString('Creati/aggiornati 1 record', $response['message']);
        $this->assertSame('Toscana', TaxonomyWhere::whereRaw("properties->>'osmfeatures_id' = ?", ['R123'])->first()->name);
        Bus::assertDispatched(FetchTaxonomyWhereGeometryJob::class);
    }

    public function test_osmfeatures_source_returns_danger_when_bbox_is_empty(): void
    {
        $app = App::factory()->create(['properties' => []]);

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'osmfeatures_4', 'app_id' => $app->id]), collect());

        $response = $action->handle($fields, collect());

        $this->assertStringContainsString('senza bbox utilizzabile', $response['danger']);
    }

    public function test_osm2cai_source_returns_danger_when_no_sectors_found(): void
    {
        $app = App::factory()->create(['map_bbox' => json_encode([10, 40, 11, 41])]);

        $client = \Mockery::mock(\Wm\WmPackage\Http\Clients\Osm2caiClient::class);
        $client->shouldReceive('getSectorsList')->once()->andReturn([]);
        $this->app->instance(\Wm\WmPackage\Http\Clients\Osm2caiClient::class, $client);

        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'osm2cai', 'app_id' => $app->id]), collect());

        $response = $action->handle($fields, collect());

        $this->assertStringContainsString('0 settori', $response['danger']);
    }

    public function test_invalid_source_type_returns_danger(): void
    {
        $action = new ImportTaxonomyWhere;
        $fields = new ActionFields(collect(['source_type' => 'not-a-real-source']), collect());

        $response = $action->handle($fields, collect());

        $this->assertStringContainsString('Sorgente non valida', $response['danger']);
    }
}
