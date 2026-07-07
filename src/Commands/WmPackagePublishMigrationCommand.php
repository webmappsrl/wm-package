<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Commands\Concerns\InteractsWithWmPackageMigrationStubs;

class WmPackagePublishMigrationCommand extends Command
{
    use InteractsWithWmPackageMigrationStubs;

    protected $signature = 'wm-package:publish-migration {stub : Nome-base dello stub, senza estensione}';

    protected $description = 'Pubblica un singolo stub di migration del wm-package non ancora presente nel progetto.';

    public function handle(): int
    {
        $baseName = $this->argument('stub');

        if ($this->findStubPath($baseName) === null) {
            $this->error("Nessuno stub trovato per \"{$baseName}\".");

            return self::FAILURE;
        }

        if ($this->isAppliedToDatabase($baseName)) {
            $this->info("\"{$baseName}\" risulta gia' applicata sul database, nessuna azione.");

            return self::SUCCESS;
        }

        if ($this->publishedFileMatchesStubContent($baseName)) {
            $this->warn("\"{$baseName}\" ha gia' una migration committata equivalente allo stub ma non ancora applicata sul database.");
            $this->line('Esegui: php artisan migrate');

            return self::FAILURE;
        }

        if ($this->findPublishedFilenameForStub($baseName) !== null) {
            $this->line("\"{$baseName}\" ha un file con lo stesso suffisso ma contenuto diverso dallo stub: pubblico la migration corretta del wm-package.");
        }

        try {
            $destination = $this->publishStubToProject($baseName);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Pubblicata: {$destination}");
        $this->line('Ricorda di committare il file pubblicato: la migration deve arrivare sul server via git, mai generata durante il deploy.');

        return self::SUCCESS;
    }
}
