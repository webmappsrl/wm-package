<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\Print\GeneratePrintPdfJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Print\PrintService;
use Wm\WmPackage\Services\Print\PrintStatus;
use Wm\WmPackage\WmPackageServiceProvider;

uses(DatabaseTransactions::class);

/** Sottoclasse di EcPoi come quella di uno shard (`wm-package.ec_poi_model`). */
class PrintRoutesShardPoi extends EcPoi
{
    protected $table = 'ec_pois';
}

/** Le rotte del dominio si caricano al boot: qui si carica il file a mano, come farebbe il provider. */
function loadPrintRoutes(): void
{
    Route::group([], __DIR__.'/../../../routes/domains/print.php');
}

beforeEach(function () {
    config([
        'wm-package.shard_name' => 'testshard',
        'wm-package.ec_track_model' => EcTrack::class,
        'cache.stores.redis.driver' => 'array',
    ]);
    Storage::fake('wmfe', ['url' => 'https://wmfe.test']);
    Cache::flush();
    Bus::fake();
    $this->printApp = App::factory()->createQuietly([
        'available_languages' => json_encode(['it', 'en']),
        'default_language' => 'it',
    ]);
    $this->track = EcTrack::factory()->createQuietly(['app_id' => $this->printApp->id, 'osmid' => null]);
});

function printFile(EcTrack $track, string $locale = 'it', ?int $modifiedAt = null): string
{
    $url = app(PrintService::class)->url($track, $locale);
    $path = ltrim(parse_url($url, PHP_URL_PATH), '/');
    Storage::disk('wmfe')->put($path, '%PDF');

    return $path;
}

it('answers 404 when the print domain is disabled', function () {
    $this->get('/print/ec-track/'.$this->track->id)->assertNotFound();
    expect(Route::has('print.show'))->toBeFalse();
});

it('registers the named routes when the domain is enabled', function () {
    loadPrintRoutes();
    app('router')->getRoutes()->refreshNameLookups();

    expect(Route::has('print.show'))->toBeTrue()
        ->and(Route::has('print.status'))->toBeTrue()
        ->and(Route::getRoutes()->getByName('print.show')->gatherMiddleware())->toContain('throttle:30,1')
        // Lo stato ha un contatore proprio, separato da `show` e dalle altre rotte dell'app.
        ->and(Route::getRoutes()->getByName('print.status')->gatherMiddleware())->toContain('throttle:60,1,print-status')
        ->not->toContain('throttle:30,1');
});

it('registers the routes through the provider when the domain is turned on', function () {
    config(['wm-package.features.print.enabled' => true]);
    $provider = app()->getProvider(WmPackageServiceProvider::class);
    // Il provider è già partito: si rilancia lo stesso metodo che al boot carica le rotte dei domini accesi.
    $method = new ReflectionMethod($provider, 'registerEnabledDomains');
    $method->invoke($provider);
    app('router')->getRoutes()->refreshNameLookups();

    expect(Route::has('print.show'))->toBeTrue();
    $this->get('/print/ec-track/'.$this->track->id.'?lang=it')->assertOk();
});

it('answers 404 when the app locale fallback is not a valid language code', function () {
    loadPrintRoutes();
    app('router')->getRoutes()->refreshNameLookups();
    $this->printApp->update(['default_language' => '']);
    config(['app.locale' => 'it_IT']);

    $this->get('/print/ec-track/'.$this->track->id)->assertNotFound();
    Bus::assertNotDispatched(GeneratePrintPdfJob::class);
});

describe('with the domain enabled', function () {
    beforeEach(function () {
        loadPrintRoutes();
        app('router')->getRoutes()->refreshNameLookups();
    });

    it('redirects to the wmfe url when the pdf is fresh', function () {
        printFile($this->track);

        $response = $this->get(route('print.show', ['type' => 'ec-track', 'id' => $this->track->id, 'lang' => 'it']));

        $response->assertRedirect(app(PrintService::class)->url($this->track, 'it'));
        $response->assertStatus(302);
        Bus::assertNotDispatched(GeneratePrintPdfJob::class);
    });

    it('shows the waiting page and dispatches the job when the pdf is missing', function () {
        $response = $this->get('/print/ec-track/'.$this->track->id.'?lang=en');

        $response->assertOk()
            ->assertSee('aria-busy="true"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('<noscript><meta http-equiv="refresh" content="10"></noscript>', false)
            ->assertSee('Preparing your PDF');
        Bus::assertDispatched(GeneratePrintPdfJob::class, fn ($job) => $job->locale === 'en' && $job->model->is($this->track));
    });

    it('dispatches the job when the pdf is stale', function () {
        $path = printFile($this->track);
        $this->track->forceFill(['updated_at' => now()->addHour()])->saveQuietly();

        $this->get('/print/ec-track/'.$this->track->id)->assertOk();

        Bus::assertDispatched(GeneratePrintPdfJob::class);
        expect($path)->not->toBeEmpty();
    });

    it('works for points of interest', function () {
        $poi = EcPoi::factory()->createQuietly(['app_id' => $this->printApp->id, 'osmid' => null]);

        $this->get('/print/ec-poi/'.$poi->id)->assertOk();

        Bus::assertDispatched(GeneratePrintPdfJob::class, fn ($job) => $job->model instanceof EcPoi);
    });

    it('resolves ec-poi through the configured poi model of the shard', function () {
        config(['wm-package.ec_poi_model' => PrintRoutesShardPoi::class]);
        $poi = EcPoi::factory()->createQuietly(['app_id' => $this->printApp->id, 'osmid' => null]);

        $this->get('/print/ec-poi/'.$poi->id)->assertOk();

        Bus::assertDispatched(GeneratePrintPdfJob::class, fn ($job) => $job->model instanceof PrintRoutesShardPoi
            && $job->model->getKey() === $poi->id);
    });

    it('polls every 5 seconds and waits as long as the job can run plus a minute', function () {
        config([
            'wm-package.features.print.compile.timeout' => 100,
            'wm-package.features.print.map_service.timeout' => 40,
            'wm-package.features.print.photos.total_timeout' => 50,
        ]);
        // 2x100 + 40 + 50 + 30 = 320 s di Job, più 60 s.
        expect(GeneratePrintPdfJob::computeTimeout())->toBe(320);

        $this->get('/print/ec-track/'.$this->track->id.'?lang=it')
            ->assertOk()
            ->assertSee('setInterval(poll, 5000)', false)
            ->assertSee('> 380 * 1000', false);
    });

    it('answers 404 without dispatching for a language outside the app languages', function (string $lang) {
        $this->get('/print/ec-track/'.$this->track->id.'?lang='.$lang)->assertNotFound();
        $this->get('/print/ec-track/'.$this->track->id.'/status?lang='.$lang)->assertNotFound();

        Bus::assertNotDispatched(GeneratePrintPdfJob::class);
    })->with(['de', '../x', 'IT', 'it/../en']);

    it('uses the app default language when lang is missing', function () {
        $this->printApp->update(['default_language' => 'en']);

        $this->get('/print/ec-track/'.$this->track->id)->assertOk();

        Bus::assertDispatched(GeneratePrintPdfJob::class, fn ($job) => $job->locale === 'en');
    });

    it('answers 404 for an unknown id, a malformed id and an unknown type', function () {
        $this->get('/print/ec-track/999999999')->assertNotFound();
        $this->get('/print/ec-track/abc')->assertNotFound();
        $this->get('/print/ugc-track/'.$this->track->id)->assertNotFound();
        $this->get('/print/ec-track/99999999999999999999/status')->assertNotFound();

        Bus::assertNotDispatched(GeneratePrintPdfJob::class);
    });

    it('reports done with the url', function () {
        PrintStatus::markDone($this->track, 'it', 'https://wmfe.test/x.pdf');

        $this->getJson(route('print.status', ['type' => 'ec-track', 'id' => $this->track->id, 'lang' => 'it']))
            ->assertOk()->assertExactJson(['status' => 'done', 'url' => 'https://wmfe.test/x.pdf']);
    });

    it('reports done from a fresh file when there is no cached status', function () {
        printFile($this->track);

        $this->getJson('/print/ec-track/'.$this->track->id.'/status?lang=it')
            ->assertOk()->assertJson(['status' => 'done'])->assertJsonStructure(['url']);
    });

    it('reports failed with the message', function () {
        PrintStatus::markFailed($this->track, 'it', 'Servizio mappe non raggiungibile');

        $this->getJson('/print/ec-track/'.$this->track->id.'/status?lang=it')
            ->assertOk()->assertExactJson(['status' => 'failed', 'message' => 'Servizio mappe non raggiungibile']);
    });

    it('reports queued when nothing is known', function () {
        $this->getJson('/print/ec-track/'.$this->track->id.'/status?lang=it')
            ->assertOk()->assertExactJson(['status' => 'queued']);
    });

    it('shows a previous failure with a retry link and dispatches only on retry', function () {
        PrintStatus::markFailed($this->track, 'it', 'Servizio mappe non raggiungibile');

        $this->get('/print/ec-track/'.$this->track->id.'?lang=it')
            ->assertOk()
            ->assertSee('Servizio mappe non raggiungibile')
            ->assertSee('retry=1', false)
            ->assertDontSee('http-equiv="refresh"', false);
        Bus::assertNotDispatched(GeneratePrintPdfJob::class);

        $this->get('/print/ec-track/'.$this->track->id.'?lang=it&retry=1')->assertOk();
        Bus::assertDispatched(GeneratePrintPdfJob::class);
    });
});
