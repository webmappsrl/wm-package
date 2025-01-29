<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Maatwebsite\Excel\Facades\Excel;
use Wm\WmPackage\Enums\ExportFormat;
use Wm\WmPackage\Exporters\ModelExporter;

/**
 * Nova Action to export models to Excel/CSV formats.
 *
 * This action allows exporting a collection of models to various formats
 * defined in the ExportFormat enum (e.g., XLSX, CSV) using the
 * Maatwebsite/Excel library.
 *
 *
 * @property array $exportModels Models to be exported
 * @property array $columns Columns to include in the export
 * @property array $relations Relations to load for the export
 * @property string $fileName Export file name (without extension)
 * @property array $styleCallback Callback to customize export styling
 * @property string $defaultFormat Default export format (@see ExportFormat)
 *
 * @see \Wm\WmPackage\Enums\ExportFormat
 */
class ExportTo extends Action
{
    use InteractsWithQueue, Queueable;

    public function name()
    {
        return __('Export to');
    }

    public function __construct(
        $columns = [],
        $relations = [],
        $fileName = 'export',
        $styles = ModelExporter::DEFAULT_STYLE,
        $defaultFormat = ExportFormat::XLSX->value
    ) {
        $this->columns = $columns;
        $this->relations = $relations;
        $this->fileName = $fileName;
        $this->styles = $styles;
        $this->defaultFormat = $defaultFormat;
    }

    /**
     * Perform the action on the given models.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $format = isset($fields->format) ? $fields->format : $this->defaultFormat;
        $uniqueId = now()->timestamp;
        $fileName = $this->fileName . '_' . $uniqueId . '.' . ExportFormat::from($format)->extension();

        Excel::store(
            new ModelExporter($models, $this->columns, $this->relations, $this->styles),
            $fileName,
            'public',
            $format,
        );

        $signedUrl = URL::temporarySignedRoute(
            'download.export',
            now()->addMinutes(5),
            ['fileName' => $fileName]
        );

        return ActionResponse::openInNewTab($signedUrl);
    }

    public function fields(NovaRequest $request)
    {
        return [
            Select::make(__('Formato'), 'format')
                ->options(ExportFormat::toArray())
                ->default([$this->defaultFormat])
                ->placeholder(__("Seleziona il formato dell'esportazione"))
                ->help(__("Seleziona il formato dell'esportazione")),
        ];
    }
}
