<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\Response;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\ActionRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Services\GeoJsonService;
use Wm\WmPackage\Support\ExportStorage;

/**
 * Action base per download "multi-formato" (XLSX + GeoJSON).
 *
 * Pattern "B": wrapper sottili (EcTrack/EcPoi/UgcTrack) configurano exporter/prefix/relazioni,
 * ma la logica comune rimane qui.
 */
abstract class AbstractDownloadMultiFormatAction extends Action
{
    public $standalone = false;

    public $withoutActionEvents = true;

    /**
     * @return array<string, string> id => label
     */
    protected function formatOptions(): array
    {
        return [
            'xlsx' => 'XLSX',
            'geojson' => 'GeoJSON',
        ];
    }

    protected function defaultFormat(): string
    {
        return 'xlsx';
    }

    /**
     * Relazioni da eager-loadare PRIMA di creare exporter/geojson.
     *
     * @return string[]
     */
    protected function eagerLoads(): array
    {
        return [];
    }

    /**
     * Prefisso nome file per l'export.
     */
    abstract protected function filePrefix(): string;

    /**
     * Exporter XLSX per la selezione (o per template quando vuota).
     */
    abstract protected function excelExporterFor(Collection $models): ?object;

    protected function supportsXlsx(): bool
    {
        return true;
    }

    protected function supportsGeoJson(): bool
    {
        return true;
    }

    protected function dispatchRequestUsing(ActionRequest $request, Response $response, ActionFields $fields): Response
    {
        /** @var Builder $query */
        $query = $request->toSelectedResourceQuery();
        $with = $this->eagerLoads();
        if ($with !== []) {
            $query->with($with);
        }

        $models = $query->get();
        $collection = $models instanceof Collection ? $models : collect($models);

        $format = (string) ($fields->format ?? $this->defaultFormat());

        // Se supportiamo un solo formato, ignora qualunque valore in input.
        if (! $this->supportsXlsx()) {
            $format = 'geojson';
        } elseif (! $this->supportsGeoJson()) {
            $format = 'xlsx';
        }

        $download = match ($format) {
            'geojson' => ExportStorage::redirectToGeoJsonExport(
                GeoJsonService::make()->modelsToFeatureCollection($collection),
                $this->filePrefix()
            ),
            default => ExportStorage::redirectToExcelExport(
                $this->excelExporterFor($collection) ?? throw new \LogicException('XLSX export not supported for this action.'),
                $this->filePrefix()
            ),
        };

        return $response->successful([$download]);
    }

    public function fields(NovaRequest $request): array
    {
        if (! $this->supportsXlsx() || ! $this->supportsGeoJson()) {
            return [];
        }

        return [
            Select::make(__('Format'), 'format')
                ->options($this->formatOptions())
                ->default($this->defaultFormat())
                ->rules('required', 'in:'.implode(',', array_keys($this->formatOptions())))
                ->displayUsingLabels(),
        ];
    }
}
