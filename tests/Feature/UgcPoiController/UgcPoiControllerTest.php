<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\Attributes\WithMigration;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Tests\TestCase;

#[WithMigration]
class UgcPoiControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected $poi;

    protected $appModel;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('wm-package.shard_name', 'test_shard');

        // Fake S3 and WMFE disks to avoid actual AWS calls and configuration issues
        Storage::fake('s3');
        Storage::fake('wmfe');

        // Set minimal dummy S3 configuration to satisfy any direct config reads
        config([
            'filesystems.disks.s3.key' => 'dummy_key',
            'filesystems.disks.s3.secret' => 'dummy_secret',
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.bucket' => 'dummy_bucket',
            'filesystems.disks.s3.url' => '',
            'filesystems.disks.wmfe.driver' => 'local', // Ensure wmfe uses local for tests if faked
            'medialibrary.disk_name' => 'public', // Use a local disk for media library in tests
        ]);

        $this->withoutMiddleware('auth.jwt');
        $this->artisan('jwt:secret --always-no');
        // Registra il servizio di autenticazione

        $this->appModel = App::factory()->create();

        /**
         * @var \Wm\WmPackage\Models\User
         */
        $this->user = User::factory()->create();

        $this->actingAs($this->user);
        $this->poi = UgcPoi::factory()->create([
            'app_id' => $this->appModel->id,
        ]);
    }

    public function test_update()
    {
        $this->actingAs($this->user, 'api');
        $poiJsonGeometry = json_decode(GeometryComputationService::make()->getModelGeometryAsGeojson($this->poi), true);

        $data = [
            'type' => 'Feature',
            'properties' => [
                'name' => 'Updated name',
                'app_id' => $this->poi->app_id,
                'id' => $this->poi->id,
            ],
            'geometry' => $poiJsonGeometry,
        ];

        $response = $this->json('POST', 'api/ugc/poi/edit', $data);

        $response->assertStatus(200);

        $this->assertEquals(UgcPoi::find($this->poi->id)->name, $data['properties']['name']);
    }

    public function test_destroy()
    {
        $this->actingAs($this->user, 'api');
        // Effettua la chiamata DELETE all'endpoint destroy
        $response = $this->json('GET', 'api/ugc/poi/delete/'.$this->poi->id);

        $response->assertStatus(200);

        // Verifica che il record sia stato rimosso dal database
        $this->assertDatabaseMissing('ugc_pois', [
            'id' => $this->poi->id,
        ]);
    }

    protected function createUserWithoutFactory(array $attributes = []): User
    {
        $user = new User;
        $user->fill(array_merge($attributes, [
            'password' => 'password',
        ]));
        $user->saveQuietly();

        return $user;
    }
}
