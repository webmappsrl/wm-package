<?php

namespace Wm\WmPackage\Jobs\FeatureCollection;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Services\Models\FeatureCollectionService;

class GenerateFeatureCollectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $featureCollectionId) {}

    public function handle(FeatureCollectionService $service): void
    {
        $fc = FeatureCollection::find($this->featureCollectionId);

        if (! $fc || $fc->mode !== 'generated') {
            return;
        }

        $service->generateAndStore($fc);
    }
}
