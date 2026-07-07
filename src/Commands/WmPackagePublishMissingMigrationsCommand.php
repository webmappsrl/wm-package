<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Commands\Concerns\InteractsWithWmPackageMigrationStubs;

class WmPackagePublishMissingMigrationsCommand extends Command
{
    use InteractsWithWmPackageMigrationStubs;

    protected $signature = 'wm-package:publish-missing-migrations
                            {--dry-run : Elenca stub non allineati; exit code non-zero se ce ne sono (gate CI)}';

    protected $description = 'Pubblica gli stub wm-package obbligatori il cui effetto non e\' ancora presente nel database.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $toPublish = $this->stubsNeedingPublishing();
        $pendingMigrate = $this->stubsPendingMigration();

        if ($pendingMigrate !== []) {
            $this->warn('Stub con migration gia\' pubblicata ma non ancora applicata sul database:');
            foreach ($pendingMigrate as $baseName) {
                $this->line("  - {$baseName}");
            }
            $this->line('  Esegui: php artisan migrate');
            $this->newLine();
        }

        if ($toPublish === [] && $pendingMigrate === []) {
            $this->info('Tutti gli stub wm-package obbligatori risultano allineati al database.');

            return self::SUCCESS;
        }

        if ($toPublish !== []) {
            $this->error(sprintf(
                '%s %d stub non allineati al database (manca migration committata equivalente):',
                $dryRun ? '[dry-run]' : 'Pubblico',
                count($toPublish),
            ));

            foreach ($toPublish as $baseName) {
                $this->line("  - {$baseName}");
                foreach ($this->schemaGapsForStub($baseName) as $gap) {
                    $this->line("      {$gap}");
                }
            }

            $this->newLine();
        }

        if ($dryRun) {
            $this->line('Risolvi in locale: php artisan wm-package:publish-missing-migrations, migrate, commit, push.');

            return self::FAILURE;
        }

        $published = 0;

        foreach ($toPublish as $baseName) {
            try {
                $destination = $this->publishStubToProject($baseName);
                $this->info("Pubblicata: {$destination}");
                $published++;
            } catch (\Throwable $exception) {
                $this->error("Errore su {$baseName}: {$exception->getMessage()}");

                return self::FAILURE;
            }
        }

        if ($pendingMigrate !== []) {
            $this->line('Esegui php artisan migrate per applicare le migration gia\' committate.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Ricorda di committare i file pubblicati e poi eseguire php artisan migrate.');

        return $published > 0 ? self::SUCCESS : self::FAILURE;
    }
}
