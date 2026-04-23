<?php

namespace Tests\Unit\Http\Resources;

use PHPUnit\Framework\TestCase;
use Wm\WmPackage\Http\Resources\EcTrackResource;

/**
 * Testa applyDemFields() in isolamento tramite una sottoclasse che espone il metodo protected.
 * Usa anonymous objects come modello (classifyField accetta object).
 */
class EcTrackResourceDemPriorityTest extends TestCase
{
    private function makeResource(): object
    {
        return new class (null) extends EcTrackResource {
            public function publicApplyDemFields(array $properties, object $model): array
            {
                return $this->applyDemFields($properties, $model);
            }
        };
    }

    private function makeModel(?array $properties, ?int $osmid = null): object
    {
        return new class ($properties, $osmid) {
            public function __construct(
                public readonly ?array $properties,
                public readonly ?int $osmid,
            ) {}
        };
    }

    public function test_usa_dem_data_quando_unica_sorgente(): void
    {
        $model = $this->makeModel(['dem_data' => ['ascent' => 500, 'distance' => 3000]]);
        $result = $this->makeResource()->publicApplyDemFields(
            ['dem_data' => ['ascent' => 500, 'distance' => 3000]],
            $model
        );

        $this->assertSame(500, $result['ascent']);
        $this->assertSame(3000, $result['distance']);
        $this->assertArrayNotHasKey('dem_data', $result);
    }

    public function test_manual_data_vince_su_dem_data(): void
    {
        $props = [
            'dem_data' => ['ascent' => 500],
            'manual_data' => ['ascent' => '73'],
        ];
        $model = $this->makeModel($props);
        $result = $this->makeResource()->publicApplyDemFields($props, $model);

        $this->assertSame('73', $result['ascent']);
        $this->assertArrayNotHasKey('manual_data', $result);
    }

    public function test_manual_data_stringa_vuota_scende_a_osm_se_osmid_presente(): void
    {
        $props = [
            'dem_data' => ['ascent' => 500],
            'osm_data' => ['ascent' => 200],
            'manual_data' => ['ascent' => ''],
        ];
        $model = $this->makeModel($props, osmid: 12345);
        $result = $this->makeResource()->publicApplyDemFields($props, $model);

        $this->assertSame(200, $result['ascent']);
        $this->assertArrayNotHasKey('osm_data', $result);
    }

    public function test_osm_data_ignorato_se_osmid_null(): void
    {
        $props = [
            'dem_data' => ['ascent' => 500],
            'osm_data' => ['ascent' => 200],
            'manual_data' => ['ascent' => ''],
        ];
        $model = $this->makeModel($props, osmid: null);
        $result = $this->makeResource()->publicApplyDemFields($props, $model);

        $this->assertSame(500, $result['ascent']);
    }

    public function test_campo_assente_se_tutte_sorgenti_mancano(): void
    {
        $model = $this->makeModel([]);
        $result = $this->makeResource()->publicApplyDemFields([], $model);

        $this->assertArrayNotHasKey('ascent', $result);
        $this->assertArrayNotHasKey('distance', $result);
    }

    public function test_properties_null_non_causa_crash(): void
    {
        $model = $this->makeModel(null);
        $result = $this->makeResource()->publicApplyDemFields([], $model);

        $this->assertIsArray($result);
    }
}
