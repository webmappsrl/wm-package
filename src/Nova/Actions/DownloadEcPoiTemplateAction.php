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

class DownloadEcPoiTemplateAction extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * Run also without selected resources.
     */
    public $standalone = true;

    public function name()
    {
        return __('Download EcPois File Template');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $format = ExportFormat::XLSX->value;
        $uniqueId = now()->timestamp;
        $fileName = "ec_pois_template_{$uniqueId}.".ExportFormat::from($format)->extension();

        $exporter = new EcPoiGeohubExporter(collect());

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

