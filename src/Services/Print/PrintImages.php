<?php

namespace Wm\WmPackage\Services\Print;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Spatie\MediaLibrary\HasMedia;
use Throwable;
use Wm\WmPackage\Services\Print\Contracts\PrintableModel;

/**
 * Le immagini della scheda PDF (oc:8982): foto del contenuto e logo dell'App, scaricate e ridotte nella
 * cartella di lavoro del compilatore. Un'immagine che non si può usare si salta con un warning sul
 * canale `print`: non fa mai fallire la scheda. I limiti sono le costanti di `PrintService`.
 */
class PrintImages
{
    /**
     * Le foto del modello (al massimo `PrintService::MAX_PHOTOS`, nell'ordine delle media) salvate in
     * `$workDir` come `photo-N.jpg`. Restituisce i nomi dei file riusciti.
     *
     * Budget complessivo (`photos.total_timeout`): oltre, le foto restanti si saltano, così il Job
     * resta sotto il proprio timeout (vedi GeneratePrintPdfJob::computeTimeout()).
     *
     * @return list<string>
     */
    public function preparePhotos(Model&PrintableModel $model, string $workDir): array
    {
        $budget = (int) config('wm-package.features.print.photos.total_timeout', PrintService::PHOTOS_TOTAL_TIMEOUT);
        $deadline = self::clock() + $budget;
        $photos = [];
        foreach (array_slice($model->printPhotoUrls(), 0, PrintService::MAX_PHOTOS) as $url) {
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
            $photoDeadline = self::clock() + min(PrintService::PHOTO_TIMEOUT, $remaining);
            if ($this->preparePhoto($url, $workDir.'/'.$name, $model, $photoDeadline)) {
                $photos[] = $name;
            }
        }

        return $photos;
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
     * `photos.max_bytes`, e i pixel si controllano in resizeTo() prima di decodificare.
     *
     * @param  float  $deadline  istante (secondi, vedi clock()) oltre il quale il download si abbandona
     */
    private function preparePhoto(string $url, string $target, Model $model, float $deadline): bool
    {
        $maxBytes = self::maxBytes();
        $download = $target.'.download';
        $skip = fn (string $reason): bool => $this->skipPhoto($reason, $url, $model);

        try {
            $remaining = $deadline - self::clock();
            if ($remaining <= 0) {
                return $skip('download oltre la scadenza');
            }
            $response = Http::timeout(max(1, (int) ceil($remaining)))->withOptions(['stream' => true])->get($url);
            if (! $response->successful()) {
                return $skip('risposta HTTP '.$response->status());
            }

            $declared = $response->header('Content-Length');
            if (is_numeric($declared) && (int) $declared > $maxBytes) {
                return $skip("dimensione dichiarata {$declared} byte oltre il limite di {$maxBytes}");
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
                        return $skip('download oltre la scadenza');
                    }
                    if (is_resource($resource)) {
                        stream_set_timeout($resource, (int) $remaining, (int) (fmod($remaining, 1) * 1000000));
                        $chunk = fread($resource, 1024 * 1024);
                        // Gli stream di alcuni wrapper (php://temp, stream dei test) non hanno la chiave `timed_out`.
                        $meta = stream_get_meta_data($resource);
                        // @phpstan-ignore nullCoalesce.offset (falso positivo: la chiave manca in alcuni wrapper)
                        if ($chunk === false || ($meta['timed_out'] ?? false)) {
                            return $skip('download oltre la scadenza');
                        }
                        $eof = feof($resource);
                    } else {
                        $chunk = $body->read(1024 * 1024);
                        $eof = $body->eof();
                    }
                    $written += strlen($chunk);
                    if ($written > $maxBytes) {
                        return $skip("corpo oltre il limite di {$maxBytes} byte");
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
                return $skip('risposta vuota');
            }

            return $this->resizeTo($download, $target, PrintService::PHOTO_MAX_SIDE, 'jpg', true, $skip, 'immagine non riconosciuta');
        } catch (Throwable $e) {
            return $skip($e->getMessage());
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
    public function prepareLogo(Model $model, string $target): bool
    {
        $app = $model->getRelationValue('app');
        if (! $app instanceof Model || ! $app instanceof HasMedia || ! method_exists($app, 'getFirstMedia')) {
            return false;
        }

        $media = null;
        foreach (PrintService::LOGO_COLLECTIONS as $collection) {
            $media = $app->getFirstMedia($collection);
            if ($media !== null) {
                break;
            }
        }
        if ($media === null) {
            return false;
        }

        $maxBytes = self::maxBytes();
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

            return $this->resizeTo($download, $target, PrintService::LOGO_MAX_SIDE, 'png', false, $skip, 'immagine non riconosciuta (formato non leggibile da GD o file corrotto)');
        } catch (Throwable $e) {
            return $skip($e->getMessage());
        } finally {
            File::delete($download);
        }
    }

    /**
     * Decodifica `$source` e la salva in `$target` nel formato dato, con lato lungo al massimo `$maxSide`
     * px. I pixel si controllano con getimagesize() (legge solo l'intestazione) contro
     * `photos.max_pixels` prima di decodificare: un esaurimento di memoria in GD è fatale, non
     * un'eccezione. `$orientate` applica l'orientamento EXIF (foto); per questo si decodifica dal file e
     * non dai byte.
     *
     * @param  callable(string): bool  $skip  segnala il motivo e restituisce false
     */
    private function resizeTo(string $source, string $target, int $maxSide, string $format, bool $orientate, callable $skip, string $unrecognized): bool
    {
        $maxPixels = (int) config('wm-package.features.print.photos.max_pixels', PrintService::PHOTO_MAX_PIXELS);
        $size = @getimagesize($source);
        if ($size === false) {
            return $skip($unrecognized);
        }
        if ($maxPixels < $size[0] * $size[1]) {
            return $skip("{$size[0]}x{$size[1]} pixel oltre il limite di {$maxPixels}");
        }

        $image = (new ImageManager(['driver' => 'gd']))->make($source);
        if ($orientate && function_exists('exif_read_data')) {
            $image->orientate();
        }
        if (max($image->width(), $image->height()) > $maxSide) {
            $image->resize($maxSide, $maxSide, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }
        File::put($target, (string) ($format === 'jpg' ? $image->encode('jpg', 85) : $image->encode('png')));
        $image->destroy();

        return true;
    }

    private static function maxBytes(): int
    {
        return (int) config('wm-package.features.print.photos.max_bytes', PrintService::PHOTO_MAX_BYTES);
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
}
