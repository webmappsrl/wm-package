<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wm\WmPackage\Jobs\Print\BuildOverviewMapJob;
use Wm\WmPackage\Jobs\Print\BuildPrintZipJob;
use Wm\WmPackage\Jobs\Print\GeneratePrintPdfJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\Print\Contracts\PrintableModel;
use Wm\WmPackage\Services\Print\PrintService;
use Wm\WmPackage\Services\Print\PrintStatus;
use Wm\WmPackage\Services\StorageService;

/**
 * Scheda PDF di tracce e POI (oc:8982): reindirizza al file se è fresco, altrimenti accoda la
 * generazione e mostra la pagina d'attesa, che interroga `status`.
 */
class PrintController extends Controller
{
    public const TYPES = ['ec-track', 'ec-poi'];

    public function show(Request $request, string $type, string $id, PrintService $service): Response
    {
        [$model, $locale] = $this->resolve($request, $type, $id);

        if ($service->isFresh($model, $locale)) {
            return redirect()->away($service->url($model, $locale));
        }

        $failed = PrintStatus::get($model, $locale);
        $failed = ($failed['status'] ?? null) === PrintStatus::FAILED ? $failed : null;
        $retry = $request->boolean('retry');

        if ($failed === null || $retry) {
            GeneratePrintPdfJob::dispatchFor($model, $locale);
            $failed = null;
        }

        $query = ['type' => $type, 'id' => $id, 'lang' => $locale];

        return response()->view('wm-package::print.waiting', [
            'locale' => $locale,
            'statusUrl' => route('print.status', $query),
            'retryUrl' => route('print.show', $query + ['retry' => 1]),
            'failedMessage' => $failed['message'] ?? null,
            'refreshSeconds' => 10,
            // Il client aspetta quanto il Job può durare, più un margine per la coda.
            'timeoutSeconds' => GeneratePrintPdfJob::computeTimeout() + 60,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function status(Request $request, string $type, string $id, PrintService $service): JsonResponse
    {
        [$model, $locale] = $this->resolve($request, $type, $id);

        $status = PrintStatus::get($model, $locale);

        if ($status === null) {
            $status = $service->isFresh($model, $locale)
                ? ['status' => PrintStatus::DONE, 'url' => $service->url($model, $locale)]
                : ['status' => PrintStatus::QUEUED];
        }

        return response()->json($status)->header('Cache-Control', 'no-store, private');
    }

    /**
     * ZIP delle schede di più tracce (`?ids[]=…&lang=`): stessa logica di `show` (redirect se lo ZIP
     * è fresco, altrimenti job e pagina d'attesa). Lo stato è su `zipStatus`.
     */
    public function zip(Request $request, StorageService $storage): Response
    {
        $ids = $this->selectionIds($request);
        if ($ids instanceof Response) {
            return $ids;
        }
        [$ids, $locale, $updatedAt] = $this->resolveSelection($request, $ids);
        $key = BuildPrintZipJob::key($ids, $locale);

        $fresh = $this->zipFreshUrl($storage, $key, $locale, $updatedAt);
        if ($fresh !== null) {
            return redirect()->away($fresh);
        }

        $failed = PrintStatus::getZip($key);
        $failed = ($failed['status'] ?? null) === PrintStatus::FAILED ? $failed : null;

        if ($failed === null || $request->boolean('retry')) {
            BuildPrintZipJob::dispatchFor($ids, $locale);
            $failed = null;
        }

        $query = ['ids' => $ids, 'lang' => $locale];

        return response()->view('wm-package::print.waiting', [
            'locale' => $locale,
            'statusUrl' => route('print.zip.status', $query),
            'retryUrl' => route('print.zip', $query + ['retry' => 1]),
            'failedMessage' => $failed['message'] ?? null,
            // Il noscript non deve superare il throttle di 5/min di `zip`; il polling resta attivo oltre l'attesa massima del job.
            'refreshSeconds' => 15,
            'timeoutSeconds' => (int) config('wm-package.print.zip.max_wait_seconds', 1800) + 120,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function zipStatus(Request $request, StorageService $storage): JsonResponse
    {
        $ids = $this->selectionIds($request);
        if ($ids instanceof Response) {
            return response()->json(['message' => 'Invalid selection'], 422);
        }
        [$ids, $locale, $updatedAt] = $this->resolveSelection($request, $ids);
        $key = BuildPrintZipJob::key($ids, $locale);

        $status = PrintStatus::getZip($key);
        if ($status === null) {
            $fresh = $this->zipFreshUrl($storage, $key, $locale, $updatedAt);
            $status = $fresh !== null
                ? ['status' => PrintStatus::DONE, 'url' => $fresh]
                : ['status' => PrintStatus::QUEUED];
        }

        return response()->json($status)->header('Cache-Control', 'no-store, private');
    }

    /**
     * Mappa d'insieme (`?ids[]=…&lang=`): stessa validazione di id e lingua dello ZIP. La scala si
     * controlla prima di accodare: oltre `overview_map.max_scale` niente job, pagina 422 che chiede di
     * ridurre la selezione. Poi redirect se il PDF è fresco, altrimenti job e pagina d'attesa.
     */
    public function overviewMap(Request $request, StorageService $storage, PrintService $service): Response
    {
        $ids = $this->selectionIds($request);
        if ($ids instanceof Response) {
            return $ids;
        }
        [$ids, $locale, $updatedAt] = $this->resolveSelection($request, $ids);
        $key = BuildOverviewMapJob::key($ids, $locale);

        $fresh = $this->overviewFreshUrl($storage, $key, $locale, $updatedAt);
        if ($fresh !== null) {
            return redirect()->away($fresh);
        }

        $bbox = $service->tracksBbox((string) config('wm-package.ec_track_model'), $ids);
        if ($bbox === null) {
            return $this->selectionError($request, 'No tracks selected.');
        }
        $max = (int) config('wm-package.print.overview_map.max_scale', 50000);
        if (PrintService::overviewScale($bbox, PrintService::OVERVIEW_MAP_WIDTH_MM, PrintService::OVERVIEW_MAP_HEIGHT_MM) > $max) {
            return $this->selectionError(
                $request,
                'The selected tracks cover too large an area for a readable map (maximum scale 1::scale). Reduce the selection.',
                ['scale' => number_format($max, 0, ',', '.')]
            );
        }

        $failed = PrintStatus::getOverview($key);
        $failed = ($failed['status'] ?? null) === PrintStatus::FAILED ? $failed : null;

        if ($failed === null || $request->boolean('retry')) {
            BuildOverviewMapJob::dispatchFor($ids, $locale);
            $failed = null;
        }

        $query = ['ids' => $ids, 'lang' => $locale];

        return response()->view('wm-package::print.waiting', [
            'locale' => $locale,
            'statusUrl' => route('print.overview-map.status', $query),
            'retryUrl' => route('print.overview-map', $query + ['retry' => 1]),
            'failedMessage' => $failed['message'] ?? null,
            'refreshSeconds' => 15,
            'timeoutSeconds' => 600,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function overviewMapStatus(Request $request, StorageService $storage): JsonResponse
    {
        $ids = $this->selectionIds($request);
        if ($ids instanceof Response) {
            return response()->json(['message' => 'Invalid selection'], 422);
        }
        [$ids, $locale, $updatedAt] = $this->resolveSelection($request, $ids);
        $key = BuildOverviewMapJob::key($ids, $locale);

        $status = PrintStatus::getOverview($key);
        if ($status === null) {
            $fresh = $this->overviewFreshUrl($storage, $key, $locale, $updatedAt);
            $status = $fresh !== null
                ? ['status' => PrintStatus::DONE, 'url' => $fresh]
                : ['status' => PrintStatus::QUEUED];
        }

        return response()->json($status)->header('Cache-Control', 'no-store, private');
    }

    /** URL del PDF d'insieme se esiste, ha meno di 24 ore e non è precedente all'ultima modifica delle tracce. */
    private function overviewFreshUrl(StorageService $storage, string $key, string $locale, int $updatedAt): ?string
    {
        return $this->freshUrl($storage, BuildOverviewMapJob::path($key, $locale), $updatedAt);
    }

    /**
     * Id richiesti: interi, senza duplicati, ordinati. Vuoti o oltre `zip.max_tracks` danno una
     * pagina HTML leggibile con 422 (la rotta è un link, non una chiamata JSON).
     *
     * @return list<int>|Response
     */
    private function selectionIds(Request $request): array|Response
    {
        $raw = $request->query('ids');
        $ids = [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            $int = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
            if ($int === false || $int < 1) {
                return $this->selectionError($request, 'No tracks selected.');
            }
            $ids[$int] = $int;
        }
        $ids = array_values($ids);
        sort($ids);

        if ($ids === []) {
            return $this->selectionError($request, 'No tracks selected.');
        }
        $max = (int) config('wm-package.print.zip.max_tracks', 50);
        if (count($ids) > $max) {
            return $this->selectionError($request, 'Too many tracks selected: the maximum is :max.', ['max' => $max]);
        }

        return $ids;
    }

    private function selectionError(Request $request, string $message, array $replace = []): Response
    {
        $lang = $request->query('lang');
        $locale = is_string($lang) && preg_match(PrintService::LOCALE_PATTERN, $lang) ? $lang : (string) config('app.locale');
        $locale = preg_match(PrintService::LOCALE_PATTERN, $locale) ? $locale : 'en';

        return response()->view('wm-package::print.error', [
            'locale' => $locale,
            'message' => __($message, $replace, $locale),
        ], 422)->header('Cache-Control', 'no-store, private');
    }

    /**
     * Tracce esistenti e stampabili fra gli id (le altre si ignorano, 404 se non ne resta nessuna) e lingua:
     * deve essere in comune a tutte le App delle tracce. Se manca vale il default dell'App della
     * prima traccia se è fra le comuni, altrimenti la prima comune (404 se non ce ne sono).
     *
     * @param  list<int>  $ids
     * @return array{0: list<int>, 1: string, 2: int} id trovati, lingua, ultimo `updated_at` (timestamp)
     */
    private function resolveSelection(Request $request, array $ids): array
    {
        $class = config('wm-package.ec_track_model');
        if (! is_string($class) || ! is_subclass_of($class, PrintableModel::class)) {
            abort(404);
        }

        /** @var class-string<Model&PrintableModel> $class */
        // Le tracce di un'App con la stampa spenta si scartano, come quelle che non esistono.
        $tracks = $class::query()->with('app')->whereIn('id', $ids)->orderBy('id')->get()
            ->filter(fn (Model $track) => PrintService::enabledFor($track))
            ->values();
        if ($tracks->isEmpty()) {
            abort(404);
        }

        $common = null;
        foreach ($tracks as $track) {
            $trackApp = $track->getRelationValue('app');
            $available = $trackApp instanceof App ? $trackApp->available_languages : null;
            $available = is_string($available) ? json_decode($available, true) : $available;
            $available = is_array($available) ? $available : [];
            $common = $common === null ? $available : array_intersect($common, $available);
        }
        $common = array_values(array_filter(
            $common ?? [],
            fn ($l) => is_string($l) && preg_match(PrintService::LOCALE_PATTERN, $l)
        ));

        $requested = $request->query('lang');
        if ($requested === null || $requested === '') {
            $app = $tracks->first()->getRelationValue('app');
            $default = $app instanceof App ? (string) $app->default_language : '';
            $locale = in_array($default, $common, true) ? $default : ($common[0] ?? null);
            if ($locale === null) {
                abort(404);
            }
        } else {
            if (! is_string($requested) || ! in_array($requested, $common, true)) {
                abort(404);
            }
            $locale = $requested;
        }

        $updatedAt = (int) $tracks->max(fn ($t) => $t->getAttribute('updated_at')?->getTimestamp() ?? 0);

        return [$tracks->pluck('id')->map(fn ($id) => (int) $id)->all(), $locale, $updatedAt];
    }

    /** URL dello ZIP se esiste, ha meno di 24 ore e non è precedente all'ultima modifica delle tracce. */
    private function zipFreshUrl(StorageService $storage, string $key, string $locale, int $updatedAt): ?string
    {
        return $this->freshUrl($storage, BuildPrintZipJob::path($key, $locale), $updatedAt);
    }

    private function freshUrl(StorageService $storage, string $path, int $updatedAt): ?string
    {
        $disk = $storage->getMediaDisk();
        if (! $disk->exists($path)) {
            return null;
        }
        $modified = $disk->lastModified($path);
        if ($modified < time() - 86400 || $modified < $updatedAt) {
            return null;
        }

        return $disk->url($path);
    }

    /**
     * @return array{0: Model&PrintableModel, 1: string}
     */
    private function resolve(Request $request, string $type, string $id): array
    {
        $class = match ($type) {
            'ec-track' => config('wm-package.ec_track_model'),
            // Lo shard può sostituire il modello del POI con una sottoclasse (come in Nova\Layer).
            'ec-poi' => config('wm-package.ec_poi_model', EcPoi::class),
            default => null,
        };
        $key = filter_var($id, FILTER_VALIDATE_INT);
        if ($class === null || $key === false || ! is_subclass_of($class, PrintableModel::class)) {
            abort(404);
        }

        /** @var class-string<Model&PrintableModel> $class */
        $model = $class::find($key);
        // Con la stampa spenta per l'App la scheda non esiste: 404 come per un id inesistente.
        if (! $model instanceof Model || ! $model instanceof PrintableModel || ! PrintService::enabledFor($model)) {
            abort(404);
        }

        return [$model, $this->locale($request, $model)];
    }

    /**
     * Lingua chiesta: deve essere fra quelle dell'App; se manca vale la lingua di default dell'App.
     */
    private function locale(Request $request, Model $model): string
    {
        $app = $model->getAttribute('app_id') ? $model->getRelationValue('app') : null;
        $requested = $request->query('lang');

        if ($requested === null || $requested === '') {
            $default = $app instanceof App ? (string) $app->default_language : '';

            $default = preg_match(PrintService::LOCALE_PATTERN, $default) ? $default : (string) config('app.locale');
            if (! preg_match(PrintService::LOCALE_PATTERN, $default)) {
                abort(404);
            }

            return $default;
        }

        $available = $app instanceof App ? $app->available_languages : null;
        $available = is_string($available) ? json_decode($available, true) : $available;

        if (! is_string($requested)
            || ! preg_match(PrintService::LOCALE_PATTERN, $requested)
            || ! in_array($requested, is_array($available) ? $available : [], true)) {
            abort(404);
        }

        return $requested;
    }
}
