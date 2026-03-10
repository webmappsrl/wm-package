<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Http\Requests\NovaRequest;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelReaderType;
use Wm\WmPackage\Imports\EcTrackFromCSV;

/**
 * Kept with the legacy class name for compatibility with
 * optimized/classmap-authoritative autoload setups.
 */
class ImportEcTrackFromFile extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Upload Track File';

    /**
     * Allows running without selecting any resources.
     *
     * Note: must stay untyped to match the base Action signature.
     */
    public $standalone = true;

    public function handle(ActionFields $fields, Collection $models)
    {
        $disk = 'importer';
        $file = $fields->file ?? null;
        if (! $file) {
            return Action::danger('Please upload a valid file');
        }

        $ext = '';
        if ($file instanceof UploadedFile) {
            $ext = strtolower((string) $file->getClientOriginalExtension());
        } elseif (is_string($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        }
        $readerType = $ext === 'xlsx' ? ExcelReaderType::XLSX : ExcelReaderType::CSV;

        $import = new EcTrackFromCSV(
            saveQuietly: true,
            delimiter: ',',
            enclosure: '"',
            escape: '\\',
        );

        try {
            // Nova actions may pass either an UploadedFile (temp path) or a stored path string.
            if ($file instanceof UploadedFile) {
                Excel::import($import, $file, null, $readerType);
            } else {
                $path = ltrim((string) $file, '/');
                Excel::import($import, $path, $disk, $readerType);
            }
        } catch (\Throwable $e) {
            return Action::danger($e->getMessage());
        } finally {
            if (is_string($file) && $file !== '') {
                try {
                    Storage::disk($disk)->delete($file);
                } catch (\Throwable $e) {
                    // If delete fails, we still consider import result authoritative.
                }
            }
        }

        return Action::message('Data imported successfully');
    }

    public function fields(NovaRequest $request): array
    {
        $exampleUrl = asset('importer-examples/import-track-example.xlsx');
        $headers = 'id, from, to, ele_from, ele_to, distance, duration_forward, duration_backward, ascent, descent, ele_min, ele_max';

        return [
            File::make('Upload File', 'file')
                ->disk('importer')
                ->path('uploads')
                ->rules('required')
                ->help(
                    '<strong> Read the instruction below </strong></br></br>'
                    .'Please upload a valid .xlsx file.</br>'
                    .'<strong>The file must contain the following headers: </strong>'
                    .$headers.'</br></br>'
                    .'Please follow this example: '
                    .'<a href="'.$exampleUrl.'" target="_blank">Example</a>'
                ),
        ];
    }
}

