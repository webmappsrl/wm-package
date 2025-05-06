<?php

namespace Tests\Unit\Observers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Observers\UgcObserver;
use Wm\WmPackage\Services\GeometryComputationService;

class UgcObserverTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $webmappUser;

    protected UgcObserver $observer;

    protected GeometryComputationService $geometryComputationServiceMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->webmappUser = User::factory()->create(['email' => config('wm-package.webmapp_user_email', 'team@webmapp.it')]);

        $this->geometryComputationServiceMock = Mockery::mock(GeometryComputationService::class);
        $this->app->instance(GeometryComputationService::class, $this->geometryComputationServiceMock);

        $this->observer = new UgcObserver;
    }

    private function createTestModel(): UgcPoi
    {
        App::factory()->create(['id' => 1, 'user_id' => $this->webmappUser->id]);

        $testModel = new UgcPoi;
        $testModel->app_id = 1;
        $testModel->geometry = 'LINESTRING(0 0, 1 1)';

        return $testModel;
    }

    private function has3dTransformation(string $geometryString): bool
    {
        return str_contains($geometryString, 'ST_Force3D') || str_contains($geometryString, ' Z ');
    }

    /** @test */
    public function it_sets_authenticated_user_as_author_when_creating_model()
    {
        Auth::login($this->user);
        $testModel = $this->createTestModel();

        $transformedGeometry = 'LINESTRING Z (0 0 0, 1 1 0)';
        $this->geometryComputationServiceMock
            ->shouldReceive('convertTo3DGeometry')
            ->once()
            ->with($testModel->geometry)
            ->andReturn($transformedGeometry);

        $this->observer->creating($testModel);
        $testModel->saveQuietly();
        $this->observer->created($testModel);

        $this->assertEquals($transformedGeometry, $testModel->geometry, 'Geometry should be transformed.');
        $this->assertNotNull($testModel->author, 'Author should be set.');
        $this->assertEquals($this->user->id, $testModel->author->id, 'Author ID should match authenticated user ID.');
        $this->assertTrue($this->has3dTransformation($testModel->geometry), 'Geometry string should indicate 3D transformation.');
    }

    /** @test */
    public function it_sets_default_webmapp_user_as_author_when_no_user_authenticated()
    {
        Auth::logout();
        $testModel = $this->createTestModel();

        $transformedGeometry = 'LINESTRING Z (0 0 0, 1 1 0)';
        $this->geometryComputationServiceMock
            ->shouldReceive('convertTo3DGeometry')
            ->once()
            ->with($testModel->geometry)
            ->andReturn($transformedGeometry);

        $this->observer->creating($testModel);
        $testModel->saveQuietly();
        $this->observer->created($testModel);

        $this->assertEquals($transformedGeometry, $testModel->geometry, 'Geometry should be transformed.');
        $this->assertNotNull($testModel->author, 'Author should be set.');
        $this->assertEquals($this->webmappUser->id, $testModel->author->id, 'Author ID should match webmapp user ID.');
        $this->assertTrue($this->has3dTransformation($testModel->geometry), 'Geometry string should indicate 3D transformation.');
    }
}
