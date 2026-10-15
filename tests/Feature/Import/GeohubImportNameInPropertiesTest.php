<?php

namespace Wm\WmPackage\Tests\Feature\Import;

require_once __DIR__.'/../../Concerns/SharesGeohubConnectionWithLocal.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Observers\AbstractObserver;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

/**
 * oc:8677: l'import da Geohub salva con persistQuietly (observer spenti), ma deve scrivere
 * properties.name come un salvataggio normale.
 */
class GeohubImportNameInPropertiesTest extends TestCase
{
    use DatabaseTransactions, SharesGeohubConnectionWithLocal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();
    }

    public function test_sync_name_into_properties_copia_le_traduzioni_su_un_poi(): void
    {
        $nome = 'Chiesa '.uniqid();
        $poi = EcPoi::factory()->make([
            'name' => ['it' => $nome, 'en' => 'Church', 'de' => null],
            'properties' => [],
        ]);

        AbstractObserver::syncNameIntoProperties($poi);

        $this->assertSame(['it' => $nome, 'en' => 'Church'], $poi->properties['name']);
    }

    public function test_sync_name_into_properties_su_una_traccia(): void
    {
        $nome = 'Sentiero '.uniqid();
        $track = EcTrack::factory()->make([
            'name' => ['it' => $nome],
            'properties' => [],
            'osmid' => null,
        ]);

        AbstractObserver::syncNameIntoProperties($track);

        $this->assertSame(['it' => $nome], $track->properties['name']);
    }

    public function test_import_data_scrive_properties_name_sul_poi(): void
    {
        $geohubId = random_int(900000000, 999999999);
        $poi = EcPoi::factory()->createQuietly([
            'properties' => ['geohub_id' => $geohubId],
        ]);
        $this->assertArrayNotHasKey('name', $poi->fresh()->properties);

        $nome = 'Cathédrale '.uniqid();
        app(GeohubImportService::class)->importData(
            ['name' => ['fr' => $nome]],
            'ec_poi',
            EcPoi::class,
            $geohubId
        );

        $properties = $poi->fresh()->properties;
        $this->assertSame($nome, $properties['name']['fr']);
        $this->assertSame($geohubId, $properties['geohub_id']);
    }
}
