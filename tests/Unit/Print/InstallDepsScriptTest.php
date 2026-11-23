<?php

use Illuminate\Support\Facades\Process;

function installDepsPath(string $file = 'install-deps.sh'): string
{
    return dirname(__DIR__, 3).'/docker/render/'.$file;
}

it('ha strict mode e installa i pacchetti TeX senza raccomandati', function () {
    $script = file_get_contents(installDepsPath());

    expect($script)
        ->toContain('set -euo pipefail')
        ->toContain('texlive-xetex')
        ->toContain('texlive-fonts-recommended')
        ->toContain('fontconfig')
        ->toContain('--no-install-recommends')
        ->toContain('fc-cache');
});

it('rifiuta sistemi che non sono Debian 13 o successivi', function () {
    $script = file_get_contents(installDepsPath());

    expect($script)
        ->toContain('/etc/os-release')
        ->toContain('VERSION_ID')
        ->toContain('exit 1');
});

it('scrive il marcatore con il contenuto di VERSION', function () {
    $script = file_get_contents(installDepsPath());

    expect($script)
        ->toContain('/usr/local/share/wm-print/version')
        ->toContain('VERSION');
});

it('ha sintassi bash valida', function () {
    $result = Process::run(['bash', '-n', installDepsPath()]);

    expect($result->successful())->toBeTrue($result->errorOutput());
});

it('la versione in config coincide con il file VERSION', function () {
    $version = trim(file_get_contents(installDepsPath('VERSION')));

    expect($version)->not->toBe('')
        ->and(config('wm-package.features.print.deps_version'))->toBe($version);
});
