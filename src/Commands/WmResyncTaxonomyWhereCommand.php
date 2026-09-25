<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Services\TaxonomyWhereResyncService;

/**
 * Comando artisan per il riallineamento conservativo di properties.taxonomy_where (oc:8588).
 */
class WmResyncTaxonomyWhereCommand extends Command
{
    protected $signature = 'wm:resync-taxonomy-where
                            {--app= : ID dell\'App (obbligatorio)}
                            {--only-legacy : Solo i record senza _admin_level o nel formato oc:8487}
                            {--outputs-only : Non ricalcola, rigenera solo json statici, Elasticsearch e pois.geojson}
                            {--dry-run : Conta i record per formato senza scrivere né chiamare osmfeatures}
                            {--force : Salta la conferma quando ci sono where senza geometria (uso non interattivo)}
                            {--queue=default : Coda su cui accodare il batch (deve essere letta da un worker Horizon)}';

    protected $description = 'Riallinea properties.taxonomy_where alla forma vecchia in modo conservativo e rigenera le uscite pubbliche (oc:8588).';

    public function handle(TaxonomyWhereResyncService $service): int
    {
        $appId = (int) $this->option('app');
        if ($appId <= 0) {
            $this->error('Opzione --app obbligatoria.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $rows = [];
            foreach ($service->dryRunReport($appId) as $table => $counts) {
                $rows[] = [$table, ...array_values($counts)];
            }
            $this->table(['tabella', 'vuoti', 'formato più vecchio', 'forma vecchia', 'formato oc:8487', 'senza categorie scelte'], $rows);
            $this->info("Where senza geometria: {$service->countWheresMissingGeometry()}.");

            return self::SUCCESS;
        }

        $queue = (string) $this->option('queue');

        if ($this->option('outputs-only')) {
            RegenerateTaxonomyWhereOutputsJob::dispatch($appId)->onQueue($queue);
            $this->info('Rigenerazione delle uscite accodata.');

            return self::SUCCESS;
        }

        $missingGeometry = $service->countWheresMissingGeometry();
        if ($missingGeometry > 0) {
            $this->warn(
                "{$missingGeometry} where non hanno ancora la geometria: probabilmente i job di dettaglio dell'import ".
                'sono ancora in coda. Il riallineamento troverebbe solo una parte delle località e salverebbe nel backup un valore parziale.'
            );

            if (! $this->option('force') && ! $this->confirm('Procedere comunque?', false)) {
                // FAILURE, non SUCCESS (fix round 1, review): il comando non ha fatto quello che
                // gli era stato chiesto, e un caller non interattivo (es. una pipeline di deploy)
                // deve poter distinguere "annullato" da "eseguito" sull'exit code, non solo dal
                // messaggio. Vale anche sotto --no-interaction, dove confirm() ritorna il default
                // (false) senza chiedere nulla, prendendo comunque questo stesso ramo.
                $this->error('Operazione annullata: nessun record accodato. Rilanciare con --force per procedere comunque.');

                return self::FAILURE;
            }
        }

        $count = $service->dispatch($appId, (bool) $this->option('only-legacy'), $queue);
        $this->info("Record accodati: {$count}. La rigenerazione delle uscite parte a fine batch. Rilanciare con --dry-run per vedere cosa resta da riallineare.");

        return self::SUCCESS;
    }
}
