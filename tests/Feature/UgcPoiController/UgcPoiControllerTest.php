<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
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
            ],
            'geometry' => $poiJsonGeometry,
        ];

        $response = $this->json('PUT', 'api/ugc/poi/'.$this->poi->id, $data);

        $response->assertStatus(200);

        $this->assertEquals(UgcPoi::find($this->poi->id)->name, $data['properties']['name']);
    }

    public function test_destroy()
    {
        $this->actingAs($this->user, 'api');
        // Effettua la chiamata DELETE all'endpoint destroy
        $response = $this->json('DELETE', 'api/ugc/poi/'.$this->poi->id);
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
