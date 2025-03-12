<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\Import\GeohubImportService;

class WmImportFromGeohubCommand extends Command
{
    protected $signature = 'wm:import-from-geohub 
                            {model? : The model to import (e.g. app, ec_media, ec_track, ec_poi). If not specified, imports all}
                            {id? : Specific ID to import. If not specified, imports all}';

    protected $description = 'Import data from geohub to shard instance';

    public function __construct(protected GeohubImportService $importService)
    {
        parent::__construct();
    }

    public function handle()
    {
        $modelKey = $this->argument('model');
        $id = $this->argument('id');

        $this->info('Starting import from geohub...');

        try {
            if ($modelKey && $id) {
                $this->importService->importSingle($modelKey, $id);
                $this->logAndOutput("Job dispatched for {$modelKey} with ID {$id}");
            } elseif ($modelKey) {
                $this->importService->importAllByModel($modelKey);
                $this->logAndOutput("Jobs dispatched for all {$modelKey}s");
            } else {
                $this->importService->importAll();
                $this->logAndOutput('Jobs dispatched for all data');
            }
        } catch (\Exception $e) {
            $errorMessage = 'Import failed: ' . $e->getMessage();
            $this->logAndOutput($errorMessage, 'error');

            return 1;
        }

        return 0;
    }

    protected function logAndOutput(string $message, string $level = 'info'): void
    {
        $logger = Log::channel('wm-package-failed-jobs');

        $this->$level($message);
        $logger->$level($message);
    }
}
