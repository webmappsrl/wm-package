<?php

namespace Wm\WmPackage\Jobs\Print;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Services\FeaturesService;
use Wm\WmPackage\Services\Print\PrintService;

/**
 * Tiene aggiornate le schede PDF già generate di un modello (oc:8982).
 *
 * Rigenera solo le lingue che hanno già un `print-{locale}.pdf`: un elemento mai stampato non
 * produce nulla. Se il dominio è spento al momento dell'esecuzione non fa niente.
 */
class RegeneratePrintPdfsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Model $model)
    {
        $this->onQueue((string) config('wm-package.features.print.queue', 'print'));
    }

    public function handle(PrintService $service): void
    {
        if (! FeaturesService::isEnabled('print')) {
            return;
        }

        foreach ($service->existingLocales($this->model) as $locale) {
            GeneratePrintPdfJob::dispatchFor($this->model, $locale);
        }
    }
}
