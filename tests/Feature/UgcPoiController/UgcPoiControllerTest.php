<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Tests\TestCase;

class UgcPoiControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected $poi;

    protected $app;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $geojson = json_encode([
            'type' => 'Point',
            'coordinates' => [
                12.4533, 41.9033,
            ],
        ]);

        $this->app = App::factory()->create();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->poi = UgcPoi::factory()->create([
            'app_id' => $this->app->id,
        ]);

    }

    public function test_update()
    {
        $data = [
            'name' => 'Nome Aggiornato',
        ];
        $response = $this->json('PUT', 'api/ugc/pois/'.$this->poi->id, $data);
        $response->assertStatus(200);

        $this->assertDatabaseHas('ugc_pois', [
            'id' => $this->poi->id,
            'name' => 'Nome Aggiornato',
        ]);
    }

    public function test_destroy()
    {

        // Effettua la chiamata DELETE all'endpoint destroy
        $response = $this->json('DELETE', '/ugc/pois/'.$this->poi->id);
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
