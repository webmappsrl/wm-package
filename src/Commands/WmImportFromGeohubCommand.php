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

    protected string $connection;

    public function __construct(protected GeohubImportService $importService)
    {
        parent::__construct();
        $this->connection = config('wm-geohub-import.db_connection', 'geohub');
    }

    public function handle()
    {
        $model = $this->argument('model');
        $id = $this->argument('id');

        $this->info('Starting import from geohub...');
        $logger = Log::channel(config('wm-geohub-import.import_log_channel', 'wm-package-failed-jobs'));
        $logger->info('Starting import from geohub command', [
            'model' => $model,
            'id' => $id,
        ]);

        try {
            if ($model && $id) {
                $this->importService->importSingle($model, $id);
                $message = "Imported {$model} with ID {$id}";
                $this->info($message);
                $logger->info($message);
            } elseif ($model) {
                $this->importService->importAllByModel($model);
                $message = "Imported all {$model}s";
                $this->info($message);
                $logger->info($message);
            } else {
                $this->importService->importAll();
                $message = 'Import of all data completed';
                $this->info($message);
                $logger->info($message);
            }
        } catch (\Exception $e) {
            $errorMessage = 'Import failed: '.$e->getMessage();
            $this->error($errorMessage);
            $this->error($e->getTraceAsString());
            $logger->error($errorMessage, [
                'exception' => $e,
                'model' => $model,
                'id' => $id,
            ]);

            return 1;
        }

        return 0;
    }
}
