<?php

namespace Tests\Unit\Observers;

use Illuminate\Database\Query\Expression;
use Wm\WmPackage\Observers\UgcObserver;

class UgcObserverTest extends AbstractUgcObserverTest
{
    protected UgcObserver $observer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->observer = new UgcObserver;
    }

    private function createTestModel(): TestModel
    {
        $testModel = new TestModel;
        $testModel->geometry = '{"type":"Point","coordinates":[-24.563409,95.738052,19.699962]}';

        return $testModel;
    }

    private function has3dTransformation(Expression $query)
    {
        return str_contains($query->getValue($this->app->make('db')->getQueryGrammar()), 'ST_Force3D');
    }

    /** @test */
    public function it_sets_authenticated_user_as_author_when_creating_model()
    {
        $this->actingAs($this->authUser);
        $testModel = $this->createTestModel();

        $this->observer->creating($testModel);
        $this->assertEquals($this->authUser->id, $testModel->author->id, 'Author should be set to authenticated user');
        $this->assertTrue($this->has3dTransformation($testModel->geometry), 'Geometry should be transformed to 3D');
    }

    /** @test */
    public function it_sets_default_webmapp_user_as_author_when_no_user_authenticated()
    {
        $testModel = $this->createTestModel();
        $this->observer->creating($testModel);

        $this->assertEquals($this->webmappUser->id, $testModel->author->id, 'Author should be set to authenticated user');
        $this->assertTrue($this->has3dTransformation($testModel->geometry), 'Geometry should be transformed to 3D');
    }
}
