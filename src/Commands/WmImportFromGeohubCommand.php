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
        $model = $this->argument('model');
        $id = $this->argument('id');

        $this->info('Starting import from geohub...');

        try {
            if ($model && $id) {
                $this->importService->importSingle($model, $id);
                $this->logAndOutput("Imported {$model} with ID {$id}");
            } elseif ($model) {
                $this->importService->importAllByModel($model);
                $this->logAndOutput("Imported all {$model}s");
            } else {
                $this->importService->importAll();
                $this->logAndOutput('Import of all data completed');
            }
        } catch (\Exception $e) {
            $errorMessage = 'Import failed: '.$e->getMessage();
            $this->logAndOutput($errorMessage, 'error');

            return 1;
        }

        return 0;
    }

    protected function logAndOutput(string $message, string $level = 'info'): void
    {
        $logger = Log::channel('wm-package-failed-jobs');

        if ($level === 'error') {
            $this->error($message);
            $logger->error($message);
        } else {
            $this->info($message);
            $logger->info($message);
        }
    }
}
