<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Http\Requests\NovaRequest;
use Maatwebsite\Excel\Facades\Excel;
use Wm\WmPackage\Exporters\EcTrackImportTemplateExporter;
use Wm\WmPackage\Imports\AbstractExcelSpreadsheetImporter;
use Wm\WmPackage\Imports\EcTrackFromSpreadsheet;
use Wm\WmPackage\Support\ExportStorage;

/**
 * Upload file EcTrack da Nova: supporta solo XLSX.
 *
 * Se l'azione viene eseguita senza file, viene scaricato un template XLSX vuoto.
 */
class UploadTrackFile extends Action
{
    use InteractsWithQueue, Queueable;

    public $standalone = true;

    public function handle(ActionFields $fields, Collection $models)
    {
        $file = $fields->file;
        if (! $file) {
            return ExportStorage::redirectToExcelExport(
                new EcTrackImportTemplateExporter,
                'ec_tracks_import_template'
            );
        }

        try {
            Excel::import(new EcTrackFromSpreadsheet, $file);

            return Action::message(__('Data imported successfully'));
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return Action::danger($e->getMessage());
        }
    }

    public function fields(NovaRequest $request): array
    {
        $headersList = AbstractExcelSpreadsheetImporter::ecTrackImportValidHeadersCommaSeparated();

        return [
            File::make(__('Upload File'), 'file')
                ->help(
                    '<strong>'.e(__('Read the instructions below')).'</strong>'
                    .'<br><br>'
                    .e(__('Upload a valid .xlsx file.'))
                    .'<br><strong>'.e(__('Editable properties:')).'</strong> '
                    .e($headersList)
                    .'<br><br>'
                    .e(__('The id is required. Other fields update properties only when filled.'))
                    .'<br><br>'
                    .e(__('To download an empty template with these headers only, run the :action action from this resource menu without selecting any rows.', [
                        'action' => __('Download Tracks'),
                    ]))
                ),
        ];
    }
}
