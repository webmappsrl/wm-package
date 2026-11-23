<?php

use Illuminate\Support\Str;
use Wm\WmPackage\Exceptions\PrintCompilationException;
use Wm\WmPackage\Services\Print\XelatexCompiler;

it('builds a sandboxed command with limits', function () {
    config(['wm-package.features.print.compile.timeout' => 90, 'wm-package.features.print.compile.memory_mb' => 512]);
    $cmd = (new XelatexCompiler)->command('/tmp/w');

    expect($cmd)->toContain('/usr/bin/timeout', '90', 'xelatex', '-no-shell-escape', '-halt-on-error', '-interaction=nonstopmode')
        ->and($cmd)->toContain('--as='.(512 * 1024 * 1024))
        ->and(basename($cmd[2]))->toBe('prlimit');
});

it('restricts file access through the environment', function () {
    expect((new XelatexCompiler)->environment())->toMatchArray(['openin_any' => 'p', 'openout_any' => 'p']);
});

it('refuses an image whose marker does not match deps_version', function () {
    $marker = tempnam(sys_get_temp_dir(), 'wmprint');
    file_put_contents($marker, "1.0.0\n");
    config(['wm-package.features.print.deps_version' => '2.0.0']);

    try {
        expect(fn () => (new XelatexCompiler($marker))->compile('x'))
            ->toThrow(PrintCompilationException::class, 'immagine non allineata al package');
    } finally {
        @unlink($marker);
    }
});

it('refuses a missing marker when deps_version is set', function () {
    config(['wm-package.features.print.deps_version' => '2.0.0']);

    expect(fn () => (new XelatexCompiler('/nonexistent/marker'))->compile('x'))
        ->toThrow(PrintCompilationException::class, 'immagine non allineata al package');
});

it('compiles a minimal document', function () {
    config(['wm-package.features.print.deps_version' => null]);
    $pdf = (new XelatexCompiler)->compile('\\documentclass{article}\\begin{document}Ciao\\end{document}');

    expect(substr($pdf, 0, 4))->toBe('%PDF');
})->skip(! is_executable('/usr/bin/xelatex'), 'xelatex non installato');

it('does not execute write18', function () {
    config(['wm-package.features.print.deps_version' => null]);
    // Percorso assoluto fuori dalla workdir: sopravvive alla pulizia, quindi l'assert e' significativo.
    $marker = storage_path('app/print-write18-'.Str::uuid());
    $tex = "\\documentclass{article}\\begin{document}\\immediate\\write18{touch {$marker}}x\\end{document}";

    try {
        (new XelatexCompiler)->compile($tex);
    } catch (PrintCompilationException) {
        // l'esito della compilazione non importa: conta che il file non esista
    }

    expect(file_exists($marker))->toBeFalse();
})->skip(! is_executable('/usr/bin/xelatex'), 'xelatex non installato');

afterEach(function () {
    foreach (glob(storage_path('app/print-write18-*')) ?: [] as $f) {
        @unlink($f);
    }
});
