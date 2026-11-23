<?php

namespace Wm\WmPackage\Services\Print;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use InvalidArgumentException;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;
use Throwable;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Services\FeaturesService;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\Print\Contracts\MapRenderer;
use Wm\WmPackage\Services\Print\Contracts\PrintableModel;
use Wm\WmPackage\Services\StorageService;

/**
 * Compone la scheda PDF di un modello stampabile e la salva su wmfe (oc:8982).
 *
 * Ordine: dati della view, mappa (un errore del servizio mappe risale), foto (una foto che
 * fallisce si salta), compilazione, salvataggio. Il PDF esistente si sostituisce solo a
 * compilazione riuscita: se qualcosa fallisce prima, resta quello vecchio.
 */
class PrintService
{
    /** Foto incluse nella scheda, nell'ordine delle media. */
    public const MAX_PHOTOS = 6;

    /** Lato lungo massimo delle foto passate a xelatex, in px. */
    public const PHOTO_MAX_SIDE = 2000;

    /** Limiti di default delle foto (sovrascrivibili in `features.print.photos`). */
    public const PHOTO_MAX_BYTES = 31457280;

    public const PHOTO_MAX_PIXELS = 50000000;

    /** Lingue ammesse nel nome del file e nella view: `it`, `en`, `pt-br`, ... */
    public const LOCALE_PATTERN = '/^[a-z]{2}(-[a-z0-9]{2,8})?$/D';

    /** Timeout del download di una foto, in secondi. */
    public const PHOTO_TIMEOUT = 30;

    /** Budget di default del download di tutte le foto di una scheda, in secondi (`photos.total_timeout`). */
    public const PHOTOS_TOTAL_TIMEOUT = 60;

    /** Dimensioni della mappa nella scheda (A4 con margini di 18 mm, circa 0,38 dell'altezza utile). */
    public const MAP_WIDTH_MM = 174.0;

    public const MAP_HEIGHT_MM = 98.0;

    /**
     * Area della mappa d'insieme su A4 orizzontale (mm): la mappa a sinistra, la legenda in colonna a
     * destra (circa 72 mm) nella stessa pagina. Unica fonte per il controllo della scala e per il rendering.
     */
    public const OVERVIEW_MAP_WIDTH_MM = 200.0;

    public const OVERVIEW_MAP_HEIGHT_MM = 170.0;

    public const DEFAULT_PRIMARY_COLOR = '2E7D32';

    /** Nome del logo nella cartella di compilazione. */
    public const LOGO_FILE = 'logo.png';

    /** Lato lungo massimo del logo passato a xelatex, in px (in testata è alto circa 12 mm). */
    public const LOGO_MAX_SIDE = 800;

    /** Collection della media library dell'App da cui prendere il logo, in ordine di preferenza. */
    public const LOGO_COLLECTIONS = ['icon_small', 'icon'];

    public const STYLE_NAME = 'wm-print';

    /** Etichette passate alla view, chiave inglese di resources/lang. */
    public const LABEL_KEYS = [
        'Length', 'Ascent', 'Descent', 'Duration', 'Difficulty', 'Description', 'Map', 'Photos',
        'Points of interest', '© OpenStreetMap contributors, Webmapp',
    ];

    public function __construct(
        private readonly XelatexCompiler $compiler,
        private readonly MapRenderer $mapRenderer,
        private readonly StorageService $storage,
    ) {}

    /**
     * Genera la scheda nella lingua chiesta e restituisce l'URL del PDF su wmfe.
     */
    public function generate(Model&PrintableModel $model, string $locale): string
    {
        self::assertValidLocale($locale);

        $workDir = storage_path('app/print-tmp/assets-'.Str::uuid());
        File::ensureDirectoryExists($workDir);

        try {
            $styles = $this->styleFiles();
            $files = self::styleAttachments($styles);

            $mapFile = null;
            $mapSize = $this->mapSize();
            $map = $this->renderMap($model, $locale, $mapSize);
            if ($map !== null) {
                $mapFile = 'map.png';
                File::put($workDir.'/'.$mapFile, $map);
                $files[$mapFile] = $workDir.'/'.$mapFile;
            }

            $logoFile = $this->prepareLogo($model, $workDir.'/'.self::LOGO_FILE) ? self::LOGO_FILE : null;
            if ($logoFile !== null) {
                $files[$logoFile] = $workDir.'/'.$logoFile;
            }

            // Budget complessivo delle foto: oltre, le foto restanti si saltano, così il Job resta
            // sotto il proprio timeout (vedi GeneratePrintPdfJob::computeTimeout()).
            $budget = (int) config('wm-package.features.print.photos.total_timeout', self::PHOTOS_TOTAL_TIMEOUT);
            $deadline = self::clock() + $budget;
            $photos = [];
            foreach (array_slice($model->printPhotoUrls(), 0, self::MAX_PHOTOS) as $url) {
                $remaining = $deadline - self::clock();
                if ($remaining <= 0) {
                    Log::channel('print')->warning(
                        "Budget di {$budget} s per le foto esaurito: le foto restanti si saltano",
                        $this->photoContext($url, $model)
                    );
                    break;
                }
                $name = 'photo-'.(count($photos) + 1).'.jpg';
                // Scadenza dell'intero download della foto: il residuo del budget, mai oltre PHOTO_TIMEOUT.
                $photoDeadline = self::clock() + min(self::PHOTO_TIMEOUT, $remaining);
                if ($this->preparePhoto($url, $workDir.'/'.$name, $model, $photoDeadline)) {
                    $photos[] = $name;
                    $files[$name] = $workDir.'/'.$name;
                }
            }

            $data = array_merge([
                'relatedPois' => null,
            ], $model->printViewData($locale), [
                'model' => $model,
                'locale' => $locale,
                'labels' => $this->labels($locale),
                'mapFile' => $mapFile,
                'logoFile' => $logoFile,
                'photos' => $photos,
                'primaryColor' => $this->primaryColor($model),
                'secondaryColor' => $this->themeColor($model, 'secondary_color'),
                'tertiaryColor' => $this->themeColor($model, 'tertiary_color'),
                'fontContent' => $this->themeFont($model, 'font_family_content'),
                'fontHeader' => $this->themeFont($model, 'font_family_header'),
                'mapWidthMm' => $mapSize[0],
                'mapHeightMm' => $mapSize[1],
                'styleFiles' => array_keys($styles),
            ]);

            $tex = view($model->printView(), $data)->render();
            $pdf = $this->compiler->compile($tex, $files);

            return $this->storage->storeFileContents($model, $model->printFileName($locale), 'pdf', $pdf);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * Vero se il PDF della lingua esiste ed è stato scritto non prima dell'ultimo `updated_at`.
     */
    public function isFresh(Model $model, string $locale): bool
    {
        $lastModified = $this->storage->getFileLastModified($model, $this->fileName($model, $locale), 'pdf');
        if ($lastModified === null) {
            return false;
        }
        $updatedAt = $model->getAttribute($model->getUpdatedAtColumn() ?? 'updated_at');
        if ($updatedAt === null) {
            return true;
        }

        $updatedAt = $updatedAt instanceof \DateTimeInterface ? $updatedAt->getTimestamp() : strtotime((string) $updatedAt);

        return $lastModified >= $updatedAt;
    }

    /**
     * Stream di lettura del PDF della lingua su wmfe, null se il file manca (serve allo ZIP delle
     * schede, che non tiene i PDF in memoria). Chi lo riceve lo chiude.
     *
     * @return resource|null
     */
    public function stream(Model $model, string $locale)
    {
        return $this->storage->getFileStream($model, $this->fileName($model, $locale), 'pdf');
    }

    /**
     * URL pubblico su wmfe del PDF della lingua (il file può non esistere: vedi isFresh()).
     */
    public function url(Model $model, string $locale): string
    {
        return $this->storage->getFileUrl($model, $this->fileName($model, $locale), 'pdf');
    }

    /**
     * URL assoluto della rotta `print.show` per `properties.print_url` del dettaglio (senza `lang`:
     * la lingua la sceglie la rotta). Null a dominio spento, quando la rotta non esiste.
     *
     * Il package inserisce la chiave solo nel dettaglio della traccia (`EcTrack::getDetailGeojson()`):
     * non ha un endpoint di dettaglio POI proprio, quindi lo shard che ne ha uno deve aggiungere
     * `properties.print_url` con `sheetUrl('ec-poi', $id)`.
     */
    public static function sheetUrl(string $type, int|string $id): ?string
    {
        if (! FeaturesService::isEnabled('print') || ! Route::has('print.show')) {
            return null;
        }

        return route('print.show', ['type' => $type, 'id' => $id], true);
    }

    /**
     * Lingue per cui esiste un file `print-{locale}.pdf` nella cartella del modello, ordinate.
     *
     * @return list<string>
     */
    public function existingLocales(Model $model): array
    {
        $locales = [];
        foreach ($this->storage->listModelFiles($model) as $file) {
            if (preg_match('/^print-(.+)\.pdf$/', $file, $m) && preg_match(self::LOCALE_PATTERN, $m[1])) {
                $locales[] = $m[1];
            }
        }
        sort($locales);

        return $locales;
    }

    /**
     * La lingua finisce nella chiave su wmfe e nella view: tutto ciò che non è un codice di
     * lingua (es. `../x`) si rifiuta.
     *
     * @throws InvalidArgumentException
     */
    public static function assertValidLocale(string $locale): void
    {
        if (! preg_match(self::LOCALE_PATTERN, $locale)) {
            throw new InvalidArgumentException("Lingua non valida per la scheda: {$locale}");
        }
    }

    /** Raggio medio terrestre (m) della sfera usata da overviewScale(). */
    public const EARTH_RADIUS_M = 6371008.8;

    /**
     * Denominatore della scala (1:N) con cui un bbox in EPSG:4326 entra in un'area di mappa.
     *
     * Si converte il bbox in metri su una sfera di raggio EARTH_RADIUS_M: l'altezza è
     * `Δlat · π/180 · R`, la larghezza `Δlon · π/180 · R · cos(lat media)` (il parallelo si accorcia
     * col coseno; con bbox di poche decine di km l'errore sul coseno medio è trascurabile). La scala
     * è il maggiore fra i due rapporti metri/metri-di-carta, arrotondato per eccesso, così il bbox
     * sta sempre nell'area. Non include il margine che il servizio mappe può aggiungere.
     * Esempio: 0,1° x 0,1° a lat 40 su 277x180 mm = 1:61.775 (limite dall'altezza).
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox  [minLon, minLat, maxLon, maxLat]
     */
    public static function overviewScale(array $bbox, float $widthMm, float $heightMm): int
    {
        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', array_values($bbox));
        $perDegree = M_PI / 180 * self::EARTH_RADIUS_M;
        $heightM = abs($maxLat - $minLat) * $perDegree;
        $widthM = abs($maxLon - $minLon) * $perDegree * cos(deg2rad(($minLat + $maxLat) / 2));

        // Il padding che il servizio mappe può aggiungere non è contato: il tetto è approssimato.
        $scale = max($widthM / ($widthMm / 1000), $heightM / ($heightMm / 1000));

        return (int) max(1, ceil($scale));
    }

    /**
     * Bbox [minLon, minLat, maxLon, maxLat] in EPSG:4326 delle tracce (PostGIS ST_Extent), null se
     * nessuna ha geometria.
     *
     * @param  class-string<Model>  $class
     * @param  list<int>  $ids
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public function tracksBbox(string $class, array $ids): ?array
    {
        $inner = $class::query()->whereIn('id', $ids)
            ->selectRaw('ST_Extent(ST_Transform(geometry::geometry, 4326)) AS ext')->toBase();
        $row = DB::query()->fromSub($inner, 'e')->selectRaw(
            'ST_XMin(ext) AS min_lon, ST_YMin(ext) AS min_lat, ST_XMax(ext) AS max_lon, ST_YMax(ext) AS max_lat'
        )->first();

        if ($row === null || $row->min_lon === null) {
            return null;
        }

        return [(float) $row->min_lon, (float) $row->min_lat, (float) $row->max_lon, (float) $row->max_lat];
    }

    private function fileName(Model $model, string $locale): string
    {
        self::assertValidLocale($locale);

        return $model instanceof PrintableModel ? $model->printFileName($locale) : 'print-'.$locale;
    }

    /**
     * PNG della mappa, oppure null se il modello non ha geometria. Le eccezioni del servizio
     * mappe risalgono: la scheda non si genera e il PDF esistente resta.
     */
    /** @param  array{0: float, 1: float}  $mapSize */
    private function renderMap(Model&PrintableModel $model, string $locale, array $mapSize): ?string
    {
        if (! $model instanceof GeometryModel) {
            return null;
        }
        $geometry = GeometryComputationService::make()->getModelGeometryAsGeojson($model);
        $geometry = $geometry ? json_decode($geometry, true) : null;
        if (! is_array($geometry)) {
            return null;
        }

        $properties = $model->getAttribute('properties');
        $properties = is_array($properties) ? $properties : [];
        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => [
                    'id' => $model->getKey(),
                    'ref' => (string) ($properties['ref'] ?? ''),
                    'name' => $model->printTranslation('name', $locale),
                ],
            ]],
        ];

        return $this->mapRenderer->render(
            MapRenderRequest::forShard($featureCollection, ...$mapSize)
        );
    }

    /**
     * Scarica la foto e la salva come JPEG con lato lungo al massimo PHOTO_MAX_SIDE px.
     * Restituisce false (con un warning sul canale `print`) se la foto non è utilizzabile.
     *
     * Tempo limitato sull'intera richiesta, non sulla singola lettura: la richiesta è in streaming, e
     * con `stream` Guzzle usa lo StreamHandler, dove `timeout` vale per ogni lettura (un server che
     * manda un byte ogni 29 s lo rispetterebbe all'infinito). Quindi `timeout` (connessione e
     * intestazioni) è il tempo che resta fino a `$deadline`, e il corpo si legge a blocchi
     * controllando la scadenza prima di ogni lettura; sul resource PHP ogni lettura ha come timeout il
     * tempo che resta (`stream_set_timeout`). Oltre la scadenza la foto si salta.
     *
     * Memoria limitata prima della decodifica: il corpo si legge a blocchi su file fermandosi oltre
     * `photos.max_bytes`, e i pixel si controllano con getimagesize() (legge solo l'intestazione)
     * contro `photos.max_pixels`. Un esaurimento di memoria in GD è fatale, non un'eccezione.
     *
     * @param  float  $deadline  istante (secondi, vedi clock()) oltre il quale il download si abbandona
     */
    private function preparePhoto(string $url, string $target, Model $model, float $deadline): bool
    {
        $maxBytes = (int) config('wm-package.features.print.photos.max_bytes', self::PHOTO_MAX_BYTES);
        $maxPixels = (int) config('wm-package.features.print.photos.max_pixels', self::PHOTO_MAX_PIXELS);
        $download = $target.'.download';

        try {
            $remaining = $deadline - self::clock();
            if ($remaining <= 0) {
                return $this->skipPhoto('download oltre la scadenza', $url, $model);
            }
            $response = Http::timeout(max(1, (int) ceil($remaining)))->withOptions(['stream' => true])->get($url);
            if (! $response->successful()) {
                return $this->skipPhoto('risposta HTTP '.$response->status(), $url, $model);
            }

            $declared = $response->header('Content-Length');
            if (is_numeric($declared) && (int) $declared > $maxBytes) {
                return $this->skipPhoto("dimensione dichiarata {$declared} byte oltre il limite di {$maxBytes}", $url, $model);
            }

            $body = $response->toPsrResponse()->getBody();
            // Con lo StreamHandler il corpo è un resource PHP: staccato, ogni lettura ha il proprio timeout.
            // Altri stream PSR (per esempio nei test) non hanno un resource: si leggono con read().
            $resource = $body->detach();
            $handle = fopen($download, 'wb');
            $written = 0;
            try {
                while (true) {
                    $remaining = $deadline - self::clock();
                    if ($remaining <= 0) {
                        return $this->skipPhoto('download oltre la scadenza', $url, $model);
                    }
                    if (is_resource($resource)) {
                        stream_set_timeout($resource, (int) $remaining, (int) (fmod($remaining, 1) * 1000000));
                        $chunk = fread($resource, 1024 * 1024);
                        // Gli stream di alcuni wrapper (php://temp, stream dei test) non hanno la chiave `timed_out`.
                        $meta = stream_get_meta_data($resource);
                        // @phpstan-ignore nullCoalesce.offset (falso positivo: la chiave manca in alcuni wrapper)
                        if ($chunk === false || ($meta['timed_out'] ?? false)) {
                            return $this->skipPhoto('download oltre la scadenza', $url, $model);
                        }
                        $eof = feof($resource);
                    } else {
                        $chunk = $body->read(1024 * 1024);
                        $eof = $body->eof();
                    }
                    $written += strlen($chunk);
                    if ($written > $maxBytes) {
                        return $this->skipPhoto("corpo oltre il limite di {$maxBytes} byte", $url, $model);
                    }
                    fwrite($handle, $chunk);
                    if ($eof) {
                        break;
                    }
                }
            } finally {
                fclose($handle);
                is_resource($resource) ? fclose($resource) : $body->close();
            }
            if ($written === 0) {
                return $this->skipPhoto('risposta vuota', $url, $model);
            }

            $size = @getimagesize($download);
            if ($size === false) {
                return $this->skipPhoto('immagine non riconosciuta', $url, $model);
            }
            if ($maxPixels < $size[0] * $size[1]) {
                return $this->skipPhoto("{$size[0]}x{$size[1]} pixel oltre il limite di {$maxPixels}", $url, $model);
            }

            // make() dal file e non dai byte: così l'EXIF è leggibile e orientate() ha effetto.
            $image = (new ImageManager(['driver' => 'gd']))->make($download);
            if (function_exists('exif_read_data')) {
                $image->orientate();
            }
            if (max($image->width(), $image->height()) > self::PHOTO_MAX_SIDE) {
                $image->resize(self::PHOTO_MAX_SIDE, self::PHOTO_MAX_SIDE, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            }
            File::put($target, (string) $image->encode('jpg', 85));
            $image->destroy();

            return true;
        } catch (Throwable $e) {
            return $this->skipPhoto($e->getMessage(), $url, $model);
        } finally {
            File::delete($download);
        }
    }

    /**
     * Logo della scheda: il media «Icon small» dell'App del modello (`icon_small`), con ripiego su
     * «Icon» (`icon`). Si legge dal disco della media library, non via HTTP (il file è nostro, e in
     * locale l'URL pubblico può non essere raggiungibile dal container). Stessi limiti delle foto
     * (`photos.max_bytes`, `photos.max_pixels`); si salva come PNG (trasparenza conservata) con lato
     * lungo al massimo LOGO_MAX_SIDE px. Un formato che GD non legge (per esempio SVG) o un file
     * corrotto si saltano con un warning sul canale `print`: il logo non fa mai fallire la scheda.
     */
    private function prepareLogo(Model $model, string $target): bool
    {
        $app = $model->getRelationValue('app');
        if (! $app instanceof Model || ! $app instanceof HasMedia || ! method_exists($app, 'getFirstMedia')) {
            return false;
        }

        $media = null;
        foreach (self::LOGO_COLLECTIONS as $collection) {
            $media = $app->getFirstMedia($collection);
            if ($media !== null) {
                break;
            }
        }
        if ($media === null) {
            return false;
        }

        $maxBytes = (int) config('wm-package.features.print.photos.max_bytes', self::PHOTO_MAX_BYTES);
        $maxPixels = (int) config('wm-package.features.print.photos.max_pixels', self::PHOTO_MAX_PIXELS);
        $context = ['media' => $media->getKey(), 'collection' => $media->collection_name, 'app' => $app->getKey(), 'model' => class_basename($model), 'id' => $model->getKey()];
        $skip = function (string $reason) use ($context): bool {
            Log::channel('print')->warning('Logo saltato: '.$reason, $context);

            return false;
        };
        $download = $target.'.source';

        try {
            $path = $media->getPathRelativeToRoot();
            $source = Storage::disk($media->disk)->readStream($path);
            if (! is_resource($source)) {
                return $skip("file non leggibile sul disco {$media->disk}: {$path}");
            }
            $handle = @fopen($download, 'wb');
            if ($handle === false) {
                fclose($source);

                return $skip("impossibile scrivere il file locale {$download}");
            }
            try {
                $written = stream_copy_to_stream($source, $handle, $maxBytes + 1);
            } finally {
                fclose($handle);
                fclose($source);
            }
            if ($written === false || $written === 0) {
                return $skip('file vuoto');
            }
            if ($written > $maxBytes) {
                return $skip("file oltre il limite di {$maxBytes} byte");
            }

            $size = @getimagesize($download);
            if ($size === false) {
                return $skip('immagine non riconosciuta (formato non leggibile da GD o file corrotto)');
            }
            if ($maxPixels < $size[0] * $size[1]) {
                return $skip("{$size[0]}x{$size[1]} pixel oltre il limite di {$maxPixels}");
            }

            $image = (new ImageManager(['driver' => 'gd']))->make($download);
            if (max($image->width(), $image->height()) > self::LOGO_MAX_SIDE) {
                $image->resize(self::LOGO_MAX_SIDE, self::LOGO_MAX_SIDE, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            }
            File::put($target, (string) $image->encode('png'));
            $image->destroy();

            return true;
        } catch (Throwable $e) {
            return $skip($e->getMessage());
        } finally {
            File::delete($download);
        }
    }

    /** Istante corrente in secondi con i microsecondi; segue `Carbon::setTestNow()` nei test. */
    private static function clock(): float
    {
        return (float) now()->format('U.u');
    }

    private function skipPhoto(string $reason, string $url, Model $model): bool
    {
        Log::channel('print')->warning('Foto saltata: '.$reason, $this->photoContext($url, $model));

        return false;
    }

    /** @return array<string,mixed> */
    private function photoContext(string $url, Model $model): array
    {
        return ['url' => $url, 'model' => class_basename($model), 'id' => $model->getKey()];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string,string>
     */
    public function labels(string $locale, array $keys = self::LABEL_KEYS): array
    {
        $labels = [];
        foreach ($keys as $key) {
            $labels[$key] = LatexText::escape((string) __($key, [], $locale));
        }

        return $labels;
    }

    /**
     * Colore primario dal tema dell'App (`properties->theme->primary_color`), senza `#`.
     */
    public function primaryColor(Model $model): string
    {
        $app = $model->getRelationValue('app');
        $properties = $app instanceof Model ? $app->getAttribute('properties') : null;
        $color = is_array($properties) ? ($properties['theme']['primary_color'] ?? null) : null;

        return ltrim(sanitizeHexColor($color, '#'.self::DEFAULT_PRIMARY_COLOR), '#');
    }

    /**
     * Colore opzionale del tema dell'App (`properties->theme->{$key}`), esadecimale senza `#`; null se
     * vuoto o non valido (stessa validazione di primaryColor()), così la view usa il default.
     */
    public function themeColor(Model $model, string $key): ?string
    {
        $color = $this->themeValue($model, $key);
        $valid = sanitizeHexColor($color, '');

        return $valid === '' ? null : ltrim($valid, '#');
    }

    /**
     * Nome di un font del tema dell'App (`properties->theme->{$key}`), null se vuoto o non sicuro.
     * Finisce nel `.tex`: ammesse solo lettere, cifre, spazi e `-` (max 60 caratteri), niente comandi.
     */
    public function themeFont(Model $model, string $key): ?string
    {
        $name = $this->themeValue($model, $key);
        if (! is_string($name)) {
            return null;
        }
        $name = trim($name);

        return $name !== '' && mb_strlen($name) <= 60 && preg_match('/^[\p{L}\p{N} -]+$/Du', $name) ? $name : null;
    }

    /**
     * Dimensioni della mappa della scheda in mm [larghezza, altezza]: `features.print.map.width_mm` e
     * `height_mm`, ripiego sulle costanti MAP_WIDTH_MM e MAP_HEIGHT_MM se mancano o non sono positive.
     *
     * @return array{0: float, 1: float}
     */
    public function mapSize(): array
    {
        $width = (float) config('wm-package.features.print.map.width_mm', self::MAP_WIDTH_MM);
        $height = (float) config('wm-package.features.print.map.height_mm', self::MAP_HEIGHT_MM);

        return [$width > 0 ? $width : self::MAP_WIDTH_MM, $height > 0 ? $height : self::MAP_HEIGHT_MM];
    }

    private function themeValue(Model $model, string $key): mixed
    {
        $app = $model->getRelationValue('app');
        $properties = $app instanceof Model ? $app->getAttribute('properties') : null;

        return is_array($properties) ? ($properties['theme'][$key] ?? null) : null;
    }

    /**
     * Stili LaTeX da includere: nome del pacchetto (file senza `.sty`) => percorso. Solo `wm-print` del
     * package, che definisce le macro dei componenti usate dalle view (`\TitoloScheda`, `\PiePagina`,
     * `Tabella`, ...) e i default. Colori e font della scheda di default vengono dal tema dell'App
     * (vedi themeColor() e themeFont()); tutto il resto lo decide la view dello shard.
     * Resta un elenco (e `$styleFiles` nel contratto delle view) perché gli override esistenti
     * lo scorrono con `@foreach`.
     *
     * @return array<string,string>
     */
    public function styleFiles(): array
    {
        return [self::STYLE_NAME => dirname(__DIR__, 3).'/resources/print/'.self::STYLE_NAME.'.sty'];
    }

    /**
     * Allegati del compilatore per gli stili: `<nome>.sty` => percorso.
     *
     * @param  array<string,string>  $styles  come restituito da styleFiles()
     * @return array<string,string>
     */
    public static function styleAttachments(array $styles): array
    {
        $files = [];
        foreach ($styles as $name => $path) {
            $files[$name.'.sty'] = $path;
        }

        return $files;
    }

    /** Nome dello shard, dalla base dei suoi percorsi su wmfe (`StorageService::getShardBasePath()`). */
    public static function shardName(): string
    {
        return trim(StorageService::make()->getShardBasePath(), '/');
    }

    /**
     * Cartella dei temporanei della stampa su wmfe (ZIP e mappe d'insieme), sotto la base dello
     * shard: `wmfe` è condiviso fra gli shard, e la pulizia di uno non deve toccare gli altri.
     */
    public static function remoteTmpDirectory(): string
    {
        return ltrim(StorageService::make()->getShardBasePath(), '/').'print-tmp';
    }
}
