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
use Wm\WmPackage\Exporters\EcTrackGeohubExporter;

class DownloadExcelEcTrackAction extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * Non registrare eventi in action_events per questa action,
     * così evitiamo gli update di batch/status che stanno fallendo
     * e bloccano il redirect del download.
     */
    public $withoutActionEvents = true;

    public function name()
    {
        return __('Download EcTracks Excel');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $format = ExportFormat::XLSX->value;
        $uniqueId = now()->timestamp;
        $fileName = "ec_tracks_{$uniqueId}.".ExportFormat::from($format)->extension();

        // Best-effort eager loading to reduce N+1 if relations exist.
        $models->each(function ($m) {
            try {
                if (method_exists($m, 'loadMissing')) {
                    $m->loadMissing([
                        'taxonomyActivities',
                        'taxonomyThemes',
                        'taxonomyWheres',
                        'taxonomyWhens',
                        'taxonomyTargets',
                    ]);
                }
            } catch (\Throwable $e) {
            }
        });

        $exporter = new EcTrackGeohubExporter($models);

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

