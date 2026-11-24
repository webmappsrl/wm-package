<?php

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Wm\WmPackage\Exceptions\MapServiceBusyException;
use Wm\WmPackage\Exceptions\PrintCompilationException;
use Wm\WmPackage\Jobs\Print\GeneratePrintPdfJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Print\PrintService;
use Wm\WmPackage\Services\Print\PrintStatus;

beforeEach(function () {
    // I lock di ShouldBeUnique non si liberano con flush() su ArrayStore (vedi .claude/rules/test.md).
    app('cache')->forgetDriver('redis');
    app('cache')->forgetDriver(config('cache.default'));
    config(['wm-package.print.queue' => 'print']);
});

it('builds the unique id from model, id and locale', function () {
    $job = new GeneratePrintPdfJob(printUnsavedTrack(), 'it');

    expect($job->uniqueId())->toBe(printUnsavedTrack()->getMorphClass().':4242:it')
        ->and($job->uniqueFor)->toBe(600)
        ->and($job->tries)->toBe(20)
        ->and($job->maxExceptions)->toBe(2)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->deleteWhenMissingModels)->toBeTrue();
});

it('runs on the print queue', function () {
    expect((new GeneratePrintPdfJob(printUnsavedTrack(), 'it'))->queue)->toBe('print');
});

/** Traccia non salvata di un'App, non salvata, con la stampa accesa o spenta. */
function printableUnsavedTrack(bool $enabled = true): EcTrack
{
    return printUnsavedTrack(4242, 7)->setRelation('app', new App(['properties' => ['ec_pdf_enabled' => $enabled]]));
}

it('pushes only one job for the same model and locale', function () {
    Queue::fake();

    GeneratePrintPdfJob::dispatchFor(printableUnsavedTrack(), 'it');
    GeneratePrintPdfJob::dispatchFor(printableUnsavedTrack(), 'it');
    GeneratePrintPdfJob::dispatchFor(printableUnsavedTrack(), 'en');

    Queue::assertPushed(GeneratePrintPdfJob::class, 2);
    Queue::assertPushedOn('print', GeneratePrintPdfJob::class);
    expect(PrintStatus::get(printUnsavedTrack(), 'it'))->toBe(['status' => 'queued']);
});

it('pushes nothing when the app has print turned off, or the element has no app', function () {
    Queue::fake();

    GeneratePrintPdfJob::dispatchFor(printableUnsavedTrack(false), 'it');
    // Un elemento senza App non ha l'opzione: niente stampa.
    GeneratePrintPdfJob::dispatchFor(printUnsavedTrack(4243), 'it');

    Queue::assertNothingPushed();
    expect(PrintStatus::get(printUnsavedTrack(), 'it'))->toBeNull();
});

it('records done with the url', function () {
    $service = Mockery::mock(PrintService::class);
    $service->shouldReceive('generate')->once()->andReturn('https://wmfe.test/print/4242-it.pdf');

    (new GeneratePrintPdfJob(printUnsavedTrack(), 'it'))->handle($service);

    expect(PrintStatus::get(printUnsavedTrack(), 'it'))
        ->toBe(['status' => 'done', 'url' => 'https://wmfe.test/print/4242-it.pdf']);
});

it('goes back to the queue after the Retry-After when the map service is busy, without failing', function () {
    $service = Mockery::mock(PrintService::class);
    $service->shouldReceive('generate')->once()->andThrow(new MapServiceBusyException('occupato', 45));
    $job = (new GeneratePrintPdfJob(printUnsavedTrack(), 'it'))->withFakeQueueInteractions();

    $job->handle($service);

    $job->assertReleased(delay: 45);
    $job->assertNotFailed();
    expect(PrintStatus::get(printUnsavedTrack(), 'it')['status'] ?? null)->not->toBe('done');
});

it('never exposes the compilation log to the user', function () {
    $message = "Compilazione fallita:\n! Undefined control sequence.\nl.12 \\foo\n/var/www/storage/app/print-tmp/assets-1/scheda.tex";
    (new GeneratePrintPdfJob(printUnsavedTrack(), 'it'))->failed(new PrintCompilationException($message));

    $status = PrintStatus::get(printUnsavedTrack(), 'it');
    expect($status['status'])->toBe('failed')
        ->and($status['message'])->toBe('Generazione del PDF non riuscita')
        ->and($status['message'])->not->toContain('/var/www', 'print-tmp', 'Undefined control', '.tex');
});

it('hides paths and class names of unexpected failures', function () {
    (new GeneratePrintPdfJob(printUnsavedTrack(), 'en'))->failed(new RuntimeException('Cannot write /var/www/storage/x.pdf'));

    $status = PrintStatus::get(printUnsavedTrack(), 'en');
    expect($status['status'])->toBe('failed')
        ->and($status['message'])->toBe('PDF generation failed')
        ->and($status['message'])->not->toContain('/var/www');
});

it('sets the timeout to two compile passes plus map, photo budget and margin', function () {
    expect((new GeneratePrintPdfJob(printUnsavedTrack(), 'it'))->timeout)->toBe(390);

    config([
        'wm-package.print.compile.timeout' => 90,
        'wm-package.print.map_service.timeout' => 20,
        'wm-package.print.photos.total_timeout' => 10,
    ]);

    expect(GeneratePrintPdfJob::computeTimeout())->toBe(2 * 90 + 20 + 10 + 30)
        ->and((new GeneratePrintPdfJob(printUnsavedTrack(), 'it'))->timeout)->toBe(240);
});

it('takes the unique lock on the redis store', function () {
    // Dentro la transazione di Nova un lock su CACHE_STORE=database darebbe 25P02 (oc:8564).
    expect((new GeneratePrintPdfJob(printUnsavedTrack(), 'it'))->uniqueVia())->toBe(Cache::store('redis'));
});

it('is unique only until processing and never overlaps on the same model and locale', function () {
    $job = new GeneratePrintPdfJob(printUnsavedTrack(), 'it');
    $middleware = $job->middleware();

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe($job->uniqueId())
        ->and($middleware[0]->releaseAfter)->toBe(30)
        ->and($middleware[0]->expiresAfter)->toBe($job->timeout)
        ->and($middleware[0]->getLockKey($job))->toContain($job->uniqueId());
});

it('rejects an invalid locale before writing any status', function () {
    Queue::fake();

    expect(fn () => GeneratePrintPdfJob::dispatchFor(printUnsavedTrack(), '../x'))->toThrow(InvalidArgumentException::class)
        ->and(PrintStatus::get(printUnsavedTrack(), '../x'))->toBeNull();
    Queue::assertNothingPushed();
});
