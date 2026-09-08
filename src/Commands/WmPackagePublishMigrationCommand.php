<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Commands\Concerns\InteractsWithWmPackageMigrationStubs;
use Wm\WmPackage\Services\FeaturesService;

class WmPackagePublishMigrationCommand extends Command
{
    use InteractsWithWmPackageMigrationStubs;

    protected $signature = 'wm-package:publish-migration {stub : Identificatore dello stub, senza estensione. Per un dominio opzionale: <dominio>/<nome>}';

    protected $description = 'Pubblica un singolo stub di migration del wm-package non ancora presente nel progetto.';

    public function handle(): int
    {
        $identifier = $this->argument('stub');

        $this->warnIfDomainIsNotActive($identifier);

        if ($this->findStubPath($identifier) === null) {
            $this->error("Nessuno stub trovato per \"{$identifier}\".");

            return self::FAILURE;
        }

        if ($this->isAppliedToDatabase($identifier)) {
            $this->info("\"{$identifier}\" risulta gia' applicata sul database, nessuna azione.");

            return self::SUCCESS;
        }

        if ($this->publishedFileMatchesStubContent($identifier)) {
            $this->warn("\"{$identifier}\" ha gia' una migration committata equivalente allo stub ma non ancora applicata sul database.");
            $this->line('Esegui: php artisan migrate');

            return self::FAILURE;
        }

        if ($this->findPublishedFilenameForStub($identifier) !== null) {
            $this->line("\"{$identifier}\" ha un file con lo stesso suffisso ma contenuto diverso dallo stub: pubblico la migration corretta del wm-package.");
        }

        try {
            $destination = $this->publishStubToProject($identifier);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Pubblicata: {$destination}");
        $this->line('Ricorda di committare il file pubblicato: la migration deve arrivare sul server via git, mai generata durante il deploy.');

        return self::SUCCESS;
    }

    /**
     * Pubblicare resta possibile su richiesta esplicita anche per un dominio
     * spento — e' un requisito — ma il consumer va avvisato: si ritroverebbe la
     * tabella nello schema mentre il codice del dominio non viene registrato e
     * il gate lo ignora.
     */
    private function warnIfDomainIsNotActive(string $identifier): void
    {
        if (! str_contains($identifier, '/')) {
            return;
        }

        $domain = strstr($identifier, '/', true);

        if (! in_array($domain, FeaturesService::declaredDomains(), true)) {
            $this->warn("Il dominio \"{$domain}\" non e' dichiarato in config wm-package.features.");

            return;
        }

        if (! FeaturesService::isEnabled($domain)) {
            $this->warn("Il dominio \"{$domain}\" e' spento: la tabella verra' creata, ma comandi, route e risorse Nova del dominio non saranno registrati.");
        }
    }
}
