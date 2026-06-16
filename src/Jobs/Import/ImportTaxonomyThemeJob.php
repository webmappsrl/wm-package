<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Services\Import\GeohubImportService;

class ImportTaxonomyThemeJob extends ImportTaxonomyJob
{
    public $timeout = 300;

    public function getModelKey(): string
    {
        return parent::getModelKey().'theme';
    }

    protected function getForeignKey(): string
    {
        return 'taxonomy_theme_id';
    }

    protected function getRelationshipName(): string
    {
        return 'taxonomyThemes';
    }

    public function handle(GeohubImportService $importService): void
    {
        try {
            parent::handle($importService);
        } catch (\Exception $e) {
            Log::error('ImportTaxonomyThemeJob failed: '.$e->getMessage(), [
                'entity_id' => $this->entityId ?? null,
            ]);
        }
    }
}
