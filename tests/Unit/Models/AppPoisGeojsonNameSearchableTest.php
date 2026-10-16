<?php

namespace Wm\WmPackage\Tests\Unit\Models;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;

/**
 * oc:8677: in pois.geojson ogni POI ha name (oggetto tradotto) e searchable (stringa), i due campi
 * su cui cerca wm-core (store/features/ec/utils.ts).
 */
class AppPoisGeojsonNameSearchableTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['wm-package.shard_name' => 'test_shard']);
        Storage::fake('pois');
        Storage::fake('wmfe');
        Storage::fake('conf');
        // BuildAppPoisGeojsonJob è ShouldBeUnique con lock su Redis: Bus::fake() non evita
        // l'acquisizione del lock, e con il TestCase di maphub finirebbe nel Redis vero.
        config(['cache.stores.redis.driver' => 'array']);
        app('cache')->forgetDriver('redis');
        Bus::fake();
    }

    private function makePoi(App $app, array $name, array $properties = []): EcPoi
    {
        $attributes = [
            'app_id' => $app->id,
            'name' => $name,
            'properties' => $properties,
            'osmid' => null,
        ];
        if (Schema::hasColumn('ec_pois', 'global')) {
            $attributes['global'] = true;
        }

        return EcPoi::factory()->createQuietly($attributes);
    }

    /**
     * @return array<int, array<string, mixed>> properties dei POI del file, indicizzate per id
     */
    private function propertiesById(App $app): array
    {
        $byId = [];
        foreach ($app->fresh()->getAllPoisGeojson() as $feature) {
            $byId[$feature['properties']['id']] = $feature['properties'];
        }

        return $byId;
    }

    public function test_ogni_poi_ha_name_e_searchable(): void
    {
        $app = App::factory()->createQuietly(['poi_searchables' => '[]']);
        $conNome = $this->makePoi($app, ['it' => 'Pieve '.uniqid()], ['name' => ['it' => 'vecchio']]);
        $importato = $this->makePoi($app, ['fr' => 'Cathédrale '.uniqid()]);

        $properties = $this->propertiesById($app);

        foreach ([$conNome, $importato] as $poi) {
            $this->assertSame($poi->getTranslations('name'), $properties[$poi->id]['name']);
            $this->assertIsString($properties[$poi->id]['searchable']);
            $this->assertStringContainsString(
                array_values($poi->getTranslations('name'))[0],
                $properties[$poi->id]['searchable']
            );
        }
    }

    public function test_searchable_del_file_rispetta_poi_searchables_dell_app(): void
    {
        $app = App::factory()->createQuietly(['poi_searchables' => '["name"]']);
        $poi = $this->makePoi($app, ['it' => 'Pieve '.uniqid()], [
            'description' => ['it' => 'Descrizione da non cercare'],
        ]);

        $properties = $this->propertiesById($app);

        $this->assertStringContainsString('Pieve', $properties[$poi->id]['searchable']);
        $this->assertStringNotContainsString('Descrizione da non cercare', $properties[$poi->id]['searchable']);
    }

    public function test_name_senza_lingue_nulle(): void
    {
        $app = App::factory()->createQuietly(['poi_searchables' => '[]']);
        $nome = 'X '.uniqid();
        $poi = $this->makePoi($app, ['it' => $nome, 'de' => null]);

        $properties = $this->propertiesById($app);

        $this->assertSame(['it' => $nome], $properties[$poi->id]['name']);
    }

    public function test_errore_nel_searchable_restituisce_stringa_vuota_e_logga_il_poi(): void
    {
        Log::spy();
        $app = new class extends App
        {
            public function searchableFor(EcPoi $poi): string
            {
                return $this->searchableForPoi($poi);
            }
        };
        $poi = Mockery::mock(EcPoi::class)->makePartial();
        $poi->shouldReceive('getSearchableString')->andThrow(new \RuntimeException('boom'));
        $poi->id = 4242;

        $this->assertSame('', $app->searchableFor($poi));
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message): bool => str_contains($message, '4242') && str_contains($message, 'boom')
        );
    }
}
