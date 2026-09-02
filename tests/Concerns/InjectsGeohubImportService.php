<?php

namespace Wm\WmPackage\Tests\Concerns;

use Wm\WmPackage\Jobs\Import\BaseImportJob;
use Wm\WmPackage\Services\Import\GeohubImportService;

/**
 * Constructs an import job the same way production code would (plain `new`, not resolved
 * through the container), then populates the protected $geohubImportService property that
 * handle() normally sets before processDependencies() runs. Needed whenever a test invokes
 * processDependencies() directly via Reflection, bypassing handle() — without this, accessing
 * $this->geohubImportService throws "Typed property ... must not be accessed before
 * initialization", which processDependencies()'s own try/catch(\Throwable) then silently
 * swallows and logs, masking real assertion failures as "pivot stayed empty".
 */
trait InjectsGeohubImportService
{
    private function makeJobWithGeohubImportService(string $jobClass, int $geohubEntityId, GeohubImportService $geohubImportService): BaseImportJob
    {
        $job = new $jobClass($geohubEntityId);

        $property = new \ReflectionProperty($job, 'geohubImportService');
        $property->setAccessible(true);
        $property->setValue($job, $geohubImportService);

        return $job;
    }
}
