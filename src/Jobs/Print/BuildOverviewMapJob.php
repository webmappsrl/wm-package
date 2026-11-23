<?php

namespace Wm\WmPackage\Jobs\Print;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Wm\WmPackage\Jobs\Print\Concerns\BuildsPrintSelectionFile;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\Print\Contracts\MapRenderer;
use Wm\WmPackage\Services\Print\Contracts\PrintableModel;
use Wm\WmPackage\Services\Print\LatexText;
use Wm\WmPackage\Services\Print\MapRenderRequest;
use Wm\WmPackage\Services\Print\PrintService;
use Wm\WmPackage\Services\Print\PrintStatus;
use Wm\WmPackage\Services\Print\XelatexCompiler;
use Wm\WmPackage\Services\StorageService;

/**
 * Costruisce la mappa d'insieme di più tracce: un PDF A4 orizzontale con la mappa vettoriale e i
 * codici dei sentieri (oc:8982).
 *
 * Esecuzione unica e breve (un rendering della mappa, una compilazione). Il controllo della scala
 * massima spetta al controller, prima di accodare. Unico per selezione e lingua (lock su Redis). Il
 * PDF vive su wmfe in `{shard}/print-tmp/overview/` e lo toglie `wm:print-cleanup`.
 */
class BuildOverviewMapJob implements ShouldBeUnique, ShouldQueue
{
    use BuildsPrintSelectionFile, Dispatchable, InteractsWithQueue, Queueable;

    /** Sottocartella di `print-tmp` su wmfe, sotto la base dello shard (vedi BuildsPrintSelectionFile). */
    public const SUBDIRECTORY = 'overview';

    public const EXTENSION = 'pdf';

    /** Area della mappa in mm: stessa costante del controllo della scala (PrintService). */
    public const MAP_WIDTH_MM = PrintService::OVERVIEW_MAP_WIDTH_MM;

    public const MAP_HEIGHT_MM = PrintService::OVERVIEW_MAP_HEIGHT_MM;

    public const LABEL_KEYS = ['Overview map', 'Legend', '© OpenStreetMap contributors, Webmapp'];

    public int $tries = 1;

    /** Un'eccezione è terminale e va a failed(). */
    public int $maxExceptions = 1;

    public int $uniqueFor = 600;

    /** Sotto `retry_after` della coda (660 s su forestas). */
    public int $timeout = 300;

    /** @param  list<int>  $ids  id ordinati e senza duplicati */
    public function __construct(public array $ids, public string $locale)
    {
        $this->onQueue((string) config('wm-package.features.print.queue', 'print'));
    }

    public function handle(PrintService $service, MapRenderer $renderer, XelatexCompiler $compiler, StorageService $storage): void
    {
        $class = config('wm-package.ec_track_model');
        $tracks = $class::query()->with('app')->whereIn('id', $this->ids)->orderBy('id')->get();

        $features = [];
        $legend = [];
        foreach ($tracks as $track) {
            if (! $track instanceof Model || ! $track instanceof PrintableModel) {
                throw new RuntimeException('Il modello traccia non è stampabile.');
            }
            $geometry = GeometryComputationService::make()->getModelGeometryAsGeojson($track);
            $geometry = $geometry ? json_decode($geometry, true) : null;
            if (! is_array($geometry)) {
                continue;
            }
            $properties = $track->getAttribute('properties');
            $ref = trim((string) (is_array($properties) ? ($properties['ref'] ?? '') : ''));
            $name = $track->printTranslation('name', $this->locale);
            $features[] = [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => ['id' => $track->getKey(), 'ref' => $ref, 'name' => $name],
            ];
            $legend[] = ['ref' => LatexText::escape($ref), 'name' => LatexText::escape($name)];
        }
        if ($features === []) {
            throw new RuntimeException('Nessuna traccia con geometria per la mappa d\'insieme.');
        }

        $png = $renderer->render(MapRenderRequest::forShard(
            ['type' => 'FeatureCollection', 'features' => $features],
            self::MAP_WIDTH_MM,
            self::MAP_HEIGHT_MM
        ));

        $workDir = storage_path('app/print-tmp/overview-'.Str::uuid());
        File::ensureDirectoryExists($workDir);

        try {
            File::put($workDir.'/map.png', $png);
            $styles = $service->styleFiles();
            $files = PrintService::styleAttachments($styles) + ['map.png' => $workDir.'/map.png'];

            $tex = view('wm-package::print.overview-map', [
                'locale' => $this->locale,
                'labels' => $service->labels($this->locale, self::LABEL_KEYS),
                'legend' => $legend,
                'mapFile' => 'map.png',
                'mapWidthMm' => self::MAP_WIDTH_MM,
                'mapHeightMm' => self::MAP_HEIGHT_MM,
                'primaryColor' => $service->primaryColor($tracks->first()),
                'secondaryColor' => $service->themeColor($tracks->first(), 'secondary_color'),
                'tertiaryColor' => $service->themeColor($tracks->first(), 'tertiary_color'),
                'fontContent' => $service->themeFont($tracks->first(), 'font_family_content'),
                'fontHeader' => $service->themeFont($tracks->first(), 'font_family_header'),
                'styleFiles' => array_keys($styles),
            ])->render();

            $pdf = $compiler->compile($tex, $files);
        } finally {
            File::deleteDirectory($workDir);
        }

        $disk = $storage->getMediaDisk();
        $path = self::path($this->uniqueId(), $this->locale);
        if ($disk->put($path, $pdf, 'public') === false) {
            throw new RuntimeException("Scrittura su wmfe non riuscita: {$path}");
        }

        PrintStatus::putOverview($this->uniqueId(), ['status' => PrintStatus::DONE, 'url' => $disk->url($path)]);
    }

    protected static function putSelectionStatus(string $key, array $payload): void
    {
        PrintStatus::putOverview($key, $payload);
    }

    protected static function failureLogMessage(): string
    {
        return 'Costruzione della mappa d\'insieme non riuscita';
    }
}
