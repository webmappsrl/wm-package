<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Maatwebsite\Excel\Facades\Excel;
use Wm\WmPackage\Enums\ExportFormat;
use Wm\WmPackage\Exporters\EcPoiGeohubExporter;

class DownloadExcelEcPoiAction extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * Evita la scrittura su action_events (batch updates) durante i download.
     */
    public $withoutActionEvents = true;

    public function name()
    {
        return __('Download EcPois Excel');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $format = ExportFormat::XLSX->value;
        $uniqueId = now()->timestamp;
        $fileName = "ec_pois_{$uniqueId}.".ExportFormat::from($format)->extension();

        $exporter = new EcPoiGeohubExporter($models);

        Excel::store(
            $exporter,
            $fileName,
            'public',
            $format
        );

        $signedUrl = URL::temporarySignedRoute(
            'download.export',
            now()->addMinutes(5),
            ['fileName' => $fileName]
        );

        return Action::redirect($signedUrl);
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}

