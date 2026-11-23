<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Wm\WmPackage\Models\EcPoi;

/*
 * La view della scheda si sceglie su tre livelli (oc:8982): la singola App, lo shard, il package. La
 * cartella temporanea messa in testa al namespace `wm-package` fa le veci di
 * `resources/views/vendor/wm-package` dello shard.
 */

beforeEach(function () {
    $this->viewDir = sys_get_temp_dir().'/print-views-'.uniqid();
    File::ensureDirectoryExists($this->viewDir);
    View::prependNamespace('wm-package', $this->viewDir);
});

afterEach(function () {
    File::deleteDirectory($this->viewDir);
});

function printViewFile(string $relative): void
{
    $path = test()->viewDir.'/'.$relative.'.blade.php';
    File::ensureDirectoryExists(dirname($path));
    File::put($path, 'vista di prova');
}

it('uses the view of the single app when the shard has one for that app', function () {
    printViewFile('print/apps/7/ec-track');

    expect(printUnsavedTrack(1, 7)->printView())->toBe('wm-package::print.apps.7.ec-track')
        // Un'altra App dello stesso shard resta sulla view dello shard o del package.
        ->and(printUnsavedTrack(2, 8)->printView())->toBe('wm-package::print.ec-track');
});

it('keeps the view of the single app separate for tracks and pois', function () {
    printViewFile('print/apps/7/ec-poi');
    $poi = new EcPoi;
    $poi->app_id = 7;

    expect($poi->printView())->toBe('wm-package::print.apps.7.ec-poi')
        ->and(printUnsavedTrack(1, 7)->printView())->toBe('wm-package::print.ec-track');
});

it('falls back to the shard override, then to the package view, without an app view', function () {
    // Senza override dello shard: la view del package.
    expect(View::getFinder()->find('wm-package::print.ec-track'))->toStartWith(realpath(__DIR__.'/../../..'));

    // Con l'override dello shard: lo stesso nome, risolto nella cartella dello shard.
    printViewFile('print/ec-track');
    View::getFinder()->flush();
    $track = printUnsavedTrack(1, 7);

    expect($track->printView())->toBe('wm-package::print.ec-track')
        ->and(View::getFinder()->find($track->printView()))->toBe(test()->viewDir.'/print/ec-track.blade.php');
});

it('does not look for an app view when the model has no app', function () {
    printViewFile('print/apps/7/ec-track');

    expect(printUnsavedTrack(1)->printView())->toBe('wm-package::print.ec-track');
});
