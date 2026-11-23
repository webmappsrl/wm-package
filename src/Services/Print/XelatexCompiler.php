<?php

namespace Wm\WmPackage\Services\Print;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Wm\WmPackage\Exceptions\PrintCompilationException;

/**
 * Compila un sorgente .tex in PDF con xelatex, in un processo con limiti di tempo e memoria.
 *
 * Il testo degli editor finisce nel .tex: shell escape spento e accesso ai file ristretto
 * (openin_any=p, openout_any=p: solo file nella cartella di lavoro e sotto di essa).
 */
class XelatexCompiler
{
    public const DEFAULT_MARKER_PATH = '/usr/local/share/wm-print/version';

    public function __construct(private readonly ?string $markerPath = null) {}

    /**
     * @param  array<string,string>  $files  nome => percorso locale, copiati nella cartella di lavoro
     * @return string contenuto binario del PDF
     */
    public function compile(string $tex, array $files = []): string
    {
        $this->checkMarker();

        $base = storage_path('app/print-tmp');
        File::ensureDirectoryExists($base);
        $workDir = $base.'/'.Str::uuid();
        File::ensureDirectoryExists($workDir);

        try {
            foreach ($files as $name => $path) {
                $name = basename((string) $name);
                if (! File::copy($path, $workDir.'/'.$name)) {
                    throw new PrintCompilationException("Impossibile copiare il file {$name} nella cartella di lavoro");
                }
            }
            File::put($workDir.'/main.tex', $tex);

            $this->run($workDir);
            if (str_contains($this->logTail($workDir, 200), 'Rerun')) {
                $this->run($workDir);
            }

            $pdf = $workDir.'/main.pdf';
            if (! is_file($pdf)) {
                throw new PrintCompilationException('PDF non prodotto: '.$this->logTail($workDir));
            }

            return (string) file_get_contents($pdf);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * @return array<int,string>
     */
    public function command(string $workDir): array
    {
        $timeout = (int) config('wm-package.features.print.compile.timeout', 120);
        $memoryBytes = (int) config('wm-package.features.print.compile.memory_mb', 1024) * 1024 * 1024;

        return [
            '/usr/bin/timeout', (string) $timeout,
            '/usr/bin/prlimit', '--as='.$memoryBytes,
            'xelatex',
            '-no-shell-escape',
            '-halt-on-error',
            '-interaction=nonstopmode',
            '-output-directory='.$workDir,
            'main.tex',
        ];
    }

    /**
     * @return array<string,string>
     */
    public function environment(): array
    {
        return ['openin_any' => 'p', 'openout_any' => 'p'];
    }

    private function run(string $workDir): void
    {
        $timeout = (int) config('wm-package.features.print.compile.timeout', 120);
        $process = new Process($this->command($workDir), $workDir, $this->environment() + ['PATH' => '/usr/local/bin:/usr/bin:/bin']);
        // Il timeout vero è quello di /usr/bin/timeout; questo è la rete di sicurezza.
        $process->setTimeout($timeout + 10);

        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
            throw new PrintCompilationException("Compilazione oltre il timeout di {$timeout}s: ".$this->logTail($workDir));
        }

        if ($process->getExitCode() === 124) {
            throw new PrintCompilationException("Compilazione oltre il timeout di {$timeout}s: ".$this->logTail($workDir));
        }
        if (! $process->isSuccessful()) {
            throw new PrintCompilationException('Compilazione xelatex fallita (exit '.$process->getExitCode().'): '.$this->logTail($workDir));
        }
    }

    private function logTail(string $workDir, int $lines = 30): string
    {
        $log = $workDir.'/main.log';
        if (! is_file($log)) {
            return '(nessun log)';
        }

        return implode("\n", array_slice(preg_split('/\R/', rtrim((string) file_get_contents($log))), -$lines));
    }

    private function checkMarker(): void
    {
        $expected = config('wm-package.features.print.deps_version');
        if ($expected === null || $expected === '') {
            return;
        }

        $path = $this->markerPath ?? self::DEFAULT_MARKER_PATH;
        $found = is_file($path) ? trim((string) file_get_contents($path)) : null;

        if ($found !== trim((string) $expected)) {
            throw new PrintCompilationException(
                'immagine non allineata al package: attesa versione '.trim((string) $expected).', trovata '.($found ?? 'nessun marcatore')
            );
        }
    }
}
