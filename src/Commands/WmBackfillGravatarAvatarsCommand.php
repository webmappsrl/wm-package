<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Jobs\FetchGravatarAvatarJob;
use Wm\WmPackage\Models\User;

/**
 * Backfills Gravatar avatars for users that existed before FetchGravatarAvatarJob
 * started running automatically at signup.
 */
class WmBackfillGravatarAvatarsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'wm:backfill-gravatar-avatars
                            {--app-id= : app_id da assegnare al media avatar (obbligatorio)}
                            {--chunk=500 : Dimensione chunk per la query}';

    /**
     * @var string
     */
    protected $description = "Accoda FetchGravatarAvatarJob per gli utenti esistenti che non hanno ancora un avatar (nessun effetto su chi ne ha già uno, caricato o da Gravatar). --app-id è obbligatorio ed è usato per attribuire correttamente il media (evita il fallback cross-tenant hardcoded app_id=1 in MediaObserver) — NON filtra quali utenti processare: la colonna users.app_id non è un indicatore affidabile di affiliazione app (quasi sempre NULL per gli utenti registrati via app, verificato su dati reali), quindi il comando elabora tutti gli utenti senza avatar. Pensato per shard con una singola App; su shard multi-app va valutato caso per caso.";

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $appIdOption = $this->option('app-id');
        if ($appIdOption === null || $appIdOption === '') {
            $this->error("L'opzione --app-id è obbligatoria.");

            return self::FAILURE;
        }

        $appId = (int) $appIdOption;
        $chunk = max(1, (int) $this->option('chunk'));

        $query = User::query()->whereDoesntHave('media', function ($q) {
            $q->where('collection_name', 'avatar');
        });

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('Nessun utente senza avatar da elaborare.');

            return self::SUCCESS;
        }

        $this->info("Utenti da elaborare: {$total} (app_id assegnato al media: {$appId}).");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->orderBy('id')->chunkById($chunk, function ($users) use ($bar, $appId): void {
            foreach ($users as $user) {
                FetchGravatarAvatarJob::dispatch($user->id, $appId);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Completato: {$total} job accodati.");

        return self::SUCCESS;
    }
}
