<?php

namespace Tests\Unit\Services\EcTrackService;

class GetMostViewedTest extends AbstractEcTrackServiceTest
{
    private $tracks;

    const TRACKS_COUNT = 5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracks = $this->createMockTracks(self::TRACKS_COUNT);
        $this->rebindEcTrackService(MockEcTrackService::class);
    }

    public function test_get_most_viewed_for_webmapp_app(): void
    {

        $app = $this->createMockApp('it.webmapp.webmapp', $this->tracks);
        $result = $this->ecTrackService->getMostViewed($app, self::TRACKS_COUNT);

        $this->verifyResult($result);
    }

    public function test_get_most_viewed_for_specific_app(): void
    {
        $app = $this->createMockApp('it.webmapp.test', $this->tracks);
        $result = $this->ecTrackService->getMostViewed($app, self::TRACKS_COUNT);

        $this->verifyResult($result);
    }

    private function verifyResult(array $result): void
    {
        $this->assertEquals('FeatureCollection', $result['type']);
        $this->assertIsArray($result['features']);
        $this->assertCount(self::TRACKS_COUNT, $result['features']);
    }
}
