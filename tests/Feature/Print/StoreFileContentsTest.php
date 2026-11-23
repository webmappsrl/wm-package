<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\StorageService;

// Il disco wmfe non ha `throw => true`: put() e move() restituiscono false invece di lanciare.

it('stores the contents through a temporary key and returns the url', function () {
    config(['wm-package.shard_name' => 'testshard']);
    Storage::fake('wmfe', ['url' => 'https://wmfe.test']);

    $url = (new StorageService)->storeFileContents(printUnsavedTrack(7, 3), 'print-it', 'pdf', '%PDF');

    expect($url)->toBe('https://wmfe.test/testshard/3/files/ec-track/7/print-it.pdf')
        ->and(Storage::disk('wmfe')->allFiles())->toBe(['testshard/3/files/ec-track/7/print-it.pdf']);
});

it('throws and returns no url when the temporary write fails', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturn(false);
    $disk->shouldNotReceive('move');
    $disk->shouldNotReceive('url');
    Storage::set('wmfe', $disk);

    expect(fn () => (new StorageService)->storeFileContents(printUnsavedTrack(7, 3), 'print-it', 'pdf', '%PDF'))
        ->toThrow(RuntimeException::class, 'Scrittura su wmfe non riuscita');
});

it('throws, returns no url and removes the temporary key when the move fails', function () {
    $tmp = null;
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturnUsing(function ($path) use (&$tmp) {
        $tmp = $path;

        return true;
    });
    $disk->shouldReceive('move')->once()->andReturn(false);
    $disk->shouldReceive('exists')->andReturnUsing(function ($path) use (&$tmp) {
        return $path === $tmp;
    });
    $disk->shouldReceive('delete')->once()->withArgs(function ($path) use (&$tmp) {
        return $path === $tmp;
    })->andReturn(true);
    $disk->shouldNotReceive('url');
    Storage::set('wmfe', $disk);

    expect(fn () => (new StorageService)->storeFileContents(printUnsavedTrack(7, 3), 'print-it', 'pdf', '%PDF'))
        ->toThrow(RuntimeException::class, 'Spostamento su wmfe non riuscito');
    expect($tmp)->toContain('/files/ec-track/7/print-it.pdf.tmp-');
});

it('gives the stored file the download name in Content-Disposition, only when there is one', function () {
    $put = [];
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->twice()->andReturnUsing(function ($path, $contents, $options) use (&$put) {
        $put[] = $options;

        return true;
    });
    $disk->shouldReceive('move')->twice()->andReturn(true);
    $disk->shouldReceive('url')->twice()->andReturn('https://wmfe.test/x.pdf');
    Storage::set('wmfe', $disk);
    config(['wm-package.shard_name' => 'testshard']);

    (new StorageService)->storeFileContents(printUnsavedTrack(7, 3), 'print-it', 'pdf', '%PDF', 'oliena-monte-corrasi');
    (new StorageService)->storeFileContents(printUnsavedTrack(7, 3), 'print-it', 'pdf', '%PDF');

    expect($put[0])->toBe(['visibility' => 'public', 'ContentDisposition' => 'inline; filename=oliena-monte-corrasi.pdf'])
        ->and($put[1])->toBe(['visibility' => 'public']);
});

it('builds an inline Content-Disposition with a header-safe name, or none for an empty name', function () {
    expect(StorageService::contentDisposition('oliena-monte-corrasi', 'pdf'))->toBe('inline; filename=oliena-monte-corrasi.pdf')
        ->and(StorageService::contentDisposition('a"b/c\\d%e', 'pdf'))->toBe('inline; filename=abcde.pdf')
        ->and(StorageService::contentDisposition('', 'pdf'))->toBeNull()
        ->and(StorageService::contentDisposition(null, 'pdf'))->toBeNull();
});

it('names the download after the slug of the translated name, without locale or id', function () {
    config(['app.fallback_locale' => 'it']);
    $track = new EcTrack;
    $track->setTranslations('name', ['it' => 'Oliena - Monte Corrasi', 'en' => 'Oliena – Mount Corrasi']);

    expect($track->printDownloadName('it'))->toBe('oliena-monte-corrasi')
        ->and($track->printDownloadName('en'))->toBe('oliena-mount-corrasi')
        // Senza traduzione nella lingua: il ripiego di printTranslation().
        ->and($track->printDownloadName('fr'))->toBe('oliena-monte-corrasi');
});
