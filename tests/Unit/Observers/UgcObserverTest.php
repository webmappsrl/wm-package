<?php

namespace Tests\Unit\Observers;

use Wm\WmPackage\Observers\UgcObserver;

class UgcObserverTest extends AbstractUgcObserverTest
{
    protected UgcObserver $observer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->observer = new UgcObserver();
    }
    /** @test */
    public function it_sets_authenticated_user_as_author_when_creating_model()
    {
        $this->actingAs($this->authUser);
        $testModel = new TestModel();

        $this->observer->creating($testModel);
        $this->assertEquals($this->authUser->id, $testModel->author->id);
    }

    /** @test */
    public function it_sets_default_webmapp_user_as_author_when_no_user_authenticated()
    {
        $testModel = new TestModel();
        $this->observer->creating($testModel);

        $this->assertEquals($this->webmappUser->id, $testModel->author->id);
    }
}

