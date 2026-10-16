<?php

namespace Wm\WmPackage\Tests\Unit\Models;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyPoiType;

/**
 * oc:8677: EcPoi::getSearchableString() produce il testo su cui l'app cerca i POI
 * (properties.searchable di pois.geojson).
 */
class EcPoiSearchableStringTest extends TestCase
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

    private function makeApp(?string $poiSearchables): App
    {
        return App::factory()->createQuietly([
            'poi_searchables' => $poiSearchables,
        ]);
    }

    private function makePoi(App $app, array $name, array $properties = []): EcPoi
    {
        return EcPoi::factory()->createQuietly([
            'app_id' => $app->id,
            'name' => $name,
            'properties' => $properties,
            'osmid' => null,
        ]);
    }

    public function test_con_app_non_va_in_errore(): void
    {
        $app = $this->makeApp('[]');
        $poi = $this->makePoi($app, ['it' => 'Fonte '.uniqid()]);

        $this->assertIsString($poi->getSearchableString($app));
        $this->assertIsString($poi->getSearchableString());
    }

    public function test_lista_vuota_include_nome_descrizione_estratto_e_tipi(): void
    {
        $app = $this->makeApp('[]');
        $poi = $this->makePoi($app, ['it' => 'Cattedrale'], [
            'description' => ['it' => '<p>Chiesa <b>romanica</b></p>'],
            'excerpt' => ['it' => 'Breve'],
        ]);
        $poiType = TaxonomyPoiType::create([
            'name' => ['it' => 'Luoghi di fede'],
            'identifier' => 'test-'.uniqid(),
        ]);
        $poi->taxonomyPoiTypes()->attach($poiType->id);

        $searchable = $poi->fresh()->getSearchableString($app);

        $this->assertStringContainsString('Cattedrale', $searchable);
        $this->assertStringContainsString('Chiesa romanica', $searchable);
        $this->assertStringContainsString('Breve', $searchable);
        $this->assertStringContainsString('Luoghi di fede', $searchable);
        $this->assertStringNotContainsString('<p>', $searchable);
    }

    public function test_solo_name_esclude_la_descrizione(): void
    {
        $app = $this->makeApp('["name"]');
        $poi = $this->makePoi($app, ['it' => 'Cattedrale'], [
            'description' => ['it' => 'Descrizione lunga'],
        ]);

        $searchable = $poi->getSearchableString($app);

        $this->assertStringContainsString('Cattedrale', $searchable);
        $this->assertStringNotContainsString('Descrizione lunga', $searchable);
    }

    public function test_mantiene_gli_accenti(): void
    {
        $app = $this->makeApp('[]');
        $poi = $this->makePoi($app, ['fr' => 'Cathédrale Notre Dame de la Nativité']);

        $searchable = $poi->getSearchableString($app);

        $this->assertStringContainsString('Cathédrale', $searchable);
        $this->assertStringNotContainsString('\\u00e9', $searchable);
    }

    public function test_nbsp_e_entita_html_diventano_testo_cercabile(): void
    {
        $app = $this->makeApp('[]');
        $poi = $this->makePoi($app, ['it' => 'Pieve'], [
            'description' => ['it' => '<p>Torre&nbsp;campanaria &lt;b&gt;antica&lt;/b&gt;</p>'],
        ]);

        $searchable = $poi->getSearchableString($app);

        $this->assertStringContainsString('Torre campanaria', $searchable);
        $this->assertStringNotContainsString("\u{00A0}", $searchable);
        $this->assertStringNotContainsString('<b>', $searchable);
    }

    public function test_tag_adiacenti_non_incollano_le_parole(): void
    {
        $app = $this->makeApp('[]');
        $poi = $this->makePoi($app, ['it' => 'Pieve'], [
            'description' => ['it' => '<p>Uno</p><p>Due</p>'],
        ]);

        $searchable = $poi->getSearchableString($app);

        $this->assertStringContainsString('Uno Due', $searchable);
        $this->assertStringNotContainsString('UnoDue', $searchable);
    }

    public function test_campi_vuoti_non_aggiungono_parentesi(): void
    {
        $app = $this->makeApp('[]');
        $poi = $this->makePoi($app, ['it' => 'Fontana']);

        $searchable = $poi->getSearchableString($app);

        $this->assertStringNotContainsString('[]', $searchable);
        $this->assertSame(trim($searchable), $searchable);
        $this->assertStringContainsString('Fontana', $searchable);
    }

    public function test_lista_con_valori_non_stringa_vale_come_tutti_i_campi(): void
    {
        $app = $this->makeApp('["name", 5]');
        $poi = $this->makePoi($app, ['it' => 'Cattedrale'], [
            'description' => ['it' => 'Descrizione lunga'],
        ]);

        $searchable = $poi->getSearchableString($app);

        $this->assertStringContainsString('Cattedrale', $searchable);
        $this->assertStringContainsString('Descrizione lunga', $searchable);
    }

    public function test_lista_non_valida_vale_come_tutti_i_campi(): void
    {
        $app = $this->makeApp('{"name":true}');
        $poi = $this->makePoi($app, ['it' => 'Cattedrale'], [
            'description' => ['it' => 'Descrizione lunga'],
        ]);

        $searchable = $poi->getSearchableString($app);

        $this->assertStringContainsString('Cattedrale', $searchable);
        $this->assertStringContainsString('Descrizione lunga', $searchable);
    }
}
