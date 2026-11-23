<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\StorageService;

// Il disco wmfe non ha `throw => true`: put() e move() restituiscono false invece di lanciare.

function storeContentsTrack(): EcTrack
{
    $track = new EcTrack;
    $track->app_id = 3;
    $track->id = 7;

    return $track;
}

it('stores the contents through a temporary key and returns the url', function () {
    config(['wm-package.shard_name' => 'testshard']);
    Storage::fake('wmfe', ['url' => 'https://wmfe.test']);

    $url = (new StorageService)->storeFileContents(storeContentsTrack(), 'print-it', 'pdf', '%PDF');

    expect($url)->toBe('https://wmfe.test/testshard/3/files/ec-track/7/print-it.pdf')
        ->and(Storage::disk('wmfe')->allFiles())->toBe(['testshard/3/files/ec-track/7/print-it.pdf']);
});

it('throws and returns no url when the temporary write fails', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturn(false);
    $disk->shouldNotReceive('move');
    $disk->shouldNotReceive('url');
    Storage::set('wmfe', $disk);

    expect(fn () => (new StorageService)->storeFileContents(storeContentsTrack(), 'print-it', 'pdf', '%PDF'))
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

    expect(fn () => (new StorageService)->storeFileContents(storeContentsTrack(), 'print-it', 'pdf', '%PDF'))
        ->toThrow(RuntimeException::class, 'Spostamento su wmfe non riuscito');
    expect($tmp)->toContain('/files/ec-track/7/print-it.pdf.tmp-');
});
