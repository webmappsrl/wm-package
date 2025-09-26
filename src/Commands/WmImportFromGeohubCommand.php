<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\Import\GeohubImportService;

class WmImportFromGeohubCommand extends Command
{
    protected $signature = 'wm:import-from-geohub 
                            {model? : The model to import (e.g. app, ec_media, ec_track, ec_poi). If not specified, imports all}
                            {id? : Specific ID to import. If not specified, imports all}
                            {--skip-dependencies : Skip importing all dependencies}
                            {--dependencies=* : Comma-separated list of specific dependencies to import (e.g. ec_media,taxonomy_activity). If not specified, imports all dependencies unless --skip-dependencies is used}';

    protected $description = 'Import data from geohub to shard instance';

    public function __construct(protected GeohubImportService $importService)
    {
        parent::__construct();
    }

    public function handle()
    {
        $modelKey = $this->argument('model');
        $id = $this->argument('id');
        $skipDependencies = $this->option('skip-dependencies');
        $dependencies = $this->option('dependencies');

        $this->info('Starting import from geohub...');

        // Validate conflicting options
        if ($skipDependencies && !empty($dependencies)) {
            $this->error('Cannot use both --skip-dependencies and --dependencies options together.');
            return 1;
        }

        // Log dependency configuration
        if ($skipDependencies) {
            $this->info('Skipping all dependencies');
        } elseif (!empty($dependencies)) {
            $this->info('Importing only dependencies: ' . implode(', ', $dependencies));
        } else {
            $this->info('Importing all dependencies (default behavior)');
        }

        try {
            $jobData = $this->prepareJobData($skipDependencies, $dependencies);
            
            if ($modelKey && $id) {
                $this->importService->importSingle($modelKey, $id, $jobData);
                $this->logAndOutput("Job dispatched for {$modelKey} with ID {$id}");
            } elseif ($modelKey) {
                $this->importService->importAllByModel($modelKey, $jobData);
                $this->logAndOutput("Jobs dispatched for all {$modelKey}s");
            } else {
                $this->importService->importAll();
                $this->logAndOutput('Jobs dispatched for all data');
            }
        } catch (\Exception $e) {
            $errorMessage = 'Import failed: '.$e->getMessage();
            $this->logAndOutput($errorMessage, 'error');

            return 1;
        }

        return 0;
    }

    /**
     * Prepare job data with dependency configuration
     */
    protected function prepareJobData(bool $skipDependencies, array $dependencies): array
    {
        // All available dependencies
        $allDependencies = [ 'taxonomy_activity', 'taxonomy_poi_types', 'ec_poi', 'ec_track','layer', 'ec_media'];

        if ($skipDependencies) {
            // Skip all dependencies
            return [
                'allowed_dependencies' => []
            ];
        }

        if (!empty($dependencies)) {
            // Parse comma-separated values and flatten the array
            $parsedDependencies = [];
            foreach ($dependencies as $dependency) {
                $parsedDependencies = array_merge($parsedDependencies, array_map('trim', explode(',', $dependency)));
            }

            // Validate dependencies
            $validDependencies = array_intersect($parsedDependencies, $allDependencies);
            $invalidDependencies = array_diff($parsedDependencies, $allDependencies);

            if (!empty($invalidDependencies)) {
                $this->warn('Invalid dependencies ignored: ' . implode(', ', $invalidDependencies));
                $this->info('Valid dependencies are: ' . implode(', ', $allDependencies));
            }

            return [
                'allowed_dependencies' => array_unique($validDependencies)
            ];
        }

        // Default: import all dependencies
        return [
            'allowed_dependencies' => $allDependencies
        ];
    }

    protected function logAndOutput(string $message, string $level = 'info'): void
    {
        $logger = Log::channel('wm-package-failed-jobs');

        $this->$level($message);
        $logger->$level($message);
    }
}
