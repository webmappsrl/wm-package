<?php

namespace Wm\WmPackage\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Nova\Actions\ActionResponse;
use Maatwebsite\Excel\Facades\Excel;
use Wm\WmPackage\Enums\ExportFormat;

/**
 * Salva un export (Excel o raw) sul disco pubblico e restituisce una ActionResponse Nova
 * con redirect firmato alla route {@see route('download.export')}.
 *
 * Uniformare il flusso di download Excel/GeoJSON (e qualsiasi altro formato raw) in un unico punto.
 */
final class ExportStorage
{
    /**
     * Salva un export Maatwebsite Excel e restituisce il redirect firmato al download.
     *
     * @param  object  $exporter  Oggetto exporter Maatwebsite (es. FromCollection, WithHeadings, ...)
     * @param  string  $fileNamePrefix  Prefisso usato per comporre il nome file (es. "ec_tracks")
     */
    public static function redirectToExcelExport(object $exporter, string $fileNamePrefix): ActionResponse
    {
        $format = ExportFormat::XLSX;
        $fileName = self::buildFileName($fileNamePrefix, $format->extension());

        Excel::store($exporter, $fileName, 'public', $format->value);

        return self::redirect($fileName);
    }

    /**
     * Salva una FeatureCollection GeoJSON e restituisce il redirect firmato al download.
     *
     * @param  array<string, mixed>  $featureCollection  Struttura FeatureCollection completa.
     */
    public static function redirectToGeoJsonExport(array $featureCollection, string $fileNamePrefix): ActionResponse
    {
        $fileName = self::buildFileName($fileNamePrefix, 'geojson');

        $json = json_encode(
            $featureCollection,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        Storage::disk('public')->put($fileName, $json);

        return self::redirect($fileName);
    }

    private static function buildFileName(string $prefix, string $extension): string
    {
        $uniqueId = now()->timestamp;

        return "{$prefix}_{$uniqueId}.{$extension}";
    }

    private static function redirect(string $fileName): ActionResponse
    {
        $signedUrl = URL::temporarySignedRoute(
            'download.export',
            now()->addMinutes(5),
            ['fileName' => $fileName]
        );

        return ActionResponse::redirect($signedUrl);
    }
}
