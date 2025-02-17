<?php

namespace Tests\Unit\Services\EcTrackService;

use Illuminate\Support\Collection;
use Mockery;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\EcTrackService;

class GetMostViewedTest extends AbstractEcTrackServiceTest
{
    const TRACKS_COUNT = 5;

    public function test_get_most_viewed_for_webmapp_app(): void
    {
        // Creazione dei mock dei track tramite l'helper definito nell'abstract
        $tracks = $this->createMockTracks(self::TRACKS_COUNT);

        // Creazione del mock per l'applicazione
        $app = $this->createMockApp('it.webmapp.webmapp', $tracks);

        // Creazione del mock parziale del servizio e stub del metodo getMostViewed
        $mockService = Mockery::mock(EcTrackService::class)->makePartial();
        $mockService->shouldReceive('getMostViewed')
            ->with($app, self::TRACKS_COUNT)
            ->andReturn([
                'type' => 'FeatureCollection',
                'features' => $tracks->map(function ($track) {
                    return $track->getGeojson();
                })->toArray(),
            ]);

        // Invocazione del metodo da testare
        $result = $mockService->getMostViewed($app, self::TRACKS_COUNT);

        // Verifica della struttura della risposta
        $this->assertEquals('FeatureCollection', $result['type']);
        $this->assertIsArray($result['features']);
        $this->assertCount(5, $result['features']);
    }

    public function test_get_most_viewed_for_specific_app(): void
    {
        // Creazione dei mock dei track tramite l'helper
        $tracks = $this->createMockTracks(self::TRACKS_COUNT);

        // Creazione del mock per l'applicazione con attributo ecTracks
        $app = Mockery::mock(App::class);
        $app->shouldReceive('getAttribute')->with('app_id')->andReturn('it.webmapp.test');
        $app->shouldReceive('getAttribute')->with('ecTracks')->andReturn($tracks);

        // Creazione del mock parziale del servizio e stub del metodo getMostViewed
        $mockService = Mockery::mock(EcTrackService::class)->makePartial();
        $mockService->shouldReceive('getMostViewed')
            ->with($app, self::TRACKS_COUNT)
            ->andReturn([
                'type' => 'FeatureCollection',
                'features' => $tracks->map(function ($track) {
                    return $track->getGeojson();
                })->toArray(),
            ]);

        // Invocazione del metodo da testare
        $result = $mockService->getMostViewed($app, self::TRACKS_COUNT);

        // Verifica della struttura della risposta
        $this->assertEquals('FeatureCollection', $result['type']);
        $this->assertIsArray($result['features']);
        $this->assertCount(self::TRACKS_COUNT, $result['features']);
    }

    private function createMockTracks(int $count): Collection
    {
        $tracks = new Collection;
        for ($i = 0; $i < $count; $i++) {
            $tracks->push($this->createMockTrack($i));
        }

        return $tracks;
    }

    private function createMockApp(string $appId, Collection $tracks)
    {
        $app = Mockery::mock(App::class);
        $app->shouldReceive('getAttribute')->with('app_id')->andReturn($appId);
        $app->shouldReceive('getAttribute')->with('ecTracks')->andReturn($tracks);

        return $app;
    }
}
