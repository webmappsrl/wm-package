<?php

namespace Wm\WmPackage\Jobs\Print;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use Wm\WmPackage\Services\Print\PrintService;
use Wm\WmPackage\Services\Print\PrintStatus;

/**
 * Genera la scheda PDF di un modello in una lingua e ne registra lo stato per la pagina di attesa (oc:8982).
 *
 * Unico per modello e lingua **finché è in coda** (`ShouldBeUniqueUntilProcessing`): una seconda
 * richiesta mentre il primo aspetta in coda non accoda nulla, ma il lock si libera quando
 * l'esecuzione parte. Così un salvataggio che arriva durante la generazione (dati letti prima del
 * salvataggio, PDF scritto dopo) accoda una nuova generazione invece di andare perso: senza, il PDF
 * vecchio risulterebbe fresco (`lastModified >= updated_at`) per sempre. Due esecuzioni per lo stesso
 * modello e lingua non si sovrappongono comunque: `WithoutOverlapping` sulla stessa chiave rimette
 * in coda la seconda ogni 30 s finché la prima finisce.
 *
 * Se il modello viene cancellato prima dell'esecuzione il job termina senza errore.
 */
class GeneratePrintPdfJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Attesa prima di riprovare quando un'altra esecuzione per lo stesso modello e lingua è in corso, in secondi. */
    public const OVERLAP_RELEASE_SECONDS = 30;

    /** Margine del timeout del Job oltre la somma delle fasi, in secondi. */
    public const TIMEOUT_MARGIN_SECONDS = 30;

    /**
     * Tentativi: le attese di `WithoutOverlapping` sono `release()` e contano come tentativi; 20 x 30 s
     * coprono più di un'esecuzione intera. Le eccezioni vere le limita `$maxExceptions`.
     */
    public int $tries = 20;

    /** Un'eccezione si riprova una volta (come il vecchio `$tries = 2`), la seconda è terminale. */
    public int $maxExceptions = 2;

    /** Un'esecuzione oltre il timeout è terminale: va a failed() e la pagina d'attesa lo mostra. */
    public bool $failOnTimeout = true;

    /** Durata del lock di unicità, in secondi (R16). */
    public int $uniqueFor = 600;

    public bool $deleteWhenMissingModels = true;

    /** Vedi computeTimeout(). */
    public int $timeout;

    public function __construct(public Model $model, public string $locale)
    {
        $this->timeout = self::computeTimeout();
        $this->onQueue((string) config('wm-package.features.print.queue', 'print'));
    }

    /**
     * Timeout del Job: due passate di xelatex (`compile.timeout` ciascuna), la mappa
     * (`map_service.timeout`), il budget delle foto (`photos.total_timeout`) e 30 s di margine.
     * Con i default 2x120 + 60 + 60 + 30 = 390 s, sotto il `retry_after` della coda (660 s su forestas).
     */
    public static function computeTimeout(): int
    {
        return 2 * (int) config('wm-package.features.print.compile.timeout', 120)
            + (int) config('wm-package.features.print.map_service.timeout', 60)
            + (int) config('wm-package.features.print.photos.total_timeout', PrintService::PHOTOS_TOTAL_TIMEOUT)
            + self::TIMEOUT_MARGIN_SECONDS;
    }

    /**
     * Segna lo stato `queued` e accoda il job. Se per lo stesso modello e lingua ce n'è già uno in
     * coda o in corso il lock di unicità impedisce il secondo push; lo stato resta `queued`.
     */
    public static function dispatchFor(Model $model, string $locale): void
    {
        if (! preg_match(PrintService::LOCALE_PATTERN, $locale)) {
            throw new InvalidArgumentException('Lingua non valida per la scheda PDF.');
        }

        PrintStatus::markQueued($model, $locale);

        self::dispatch($model, $locale);
    }

    public function uniqueId(): string
    {
        return $this->model->getMorphClass().':'.$this->model->getKey().':'.$this->locale;
    }

    /**
     * Lock di unicità su Redis: il Job si accoda anche dall'Action Nova, dentro la transazione di
     * Nova, e con `CACHE_STORE=database` un lock già presente farebbe fallire la transazione con
     * `25P02` (oc:8564, come `BuildAppPoisGeojsonJob`).
     */
    public function uniqueVia(): Repository
    {
        return Cache::store('redis');
    }

    /**
     * Nessuna sovrapposizione fra due esecuzioni per lo stesso modello e lingua. Il lock scade dopo
     * il timeout del Job, così un worker morto non lo tiene per sempre. Questo lock si prende nel
     * worker, fuori da transazioni: lo store di default va bene.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(self::OVERLAP_RELEASE_SECONDS)
                ->expireAfter($this->timeout),
        ];
    }

    public function handle(PrintService $service): void
    {
        $url = $service->generate($this->model, $this->locale);

        PrintStatus::markDone($this->model, $this->locale, $url);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('print')->error('Generazione scheda PDF non riuscita', [
            'model' => $this->model->getMorphClass(),
            'id' => $this->model->getKey(),
            'locale' => $this->locale,
            'exception' => $exception ? $exception::class.': '.$exception->getMessage() : null,
        ]);

        PrintStatus::markFailed($this->model, $this->locale, self::userMessage($exception, $this->locale));
    }

    /**
     * Messaggio per l'utente: sempre quello generico tradotto. Il dettaglio dell'eccezione
     * (percorsi del server, coda del log di xelatex) va solo nel log.
     */
    public static function userMessage(?Throwable $exception, string $locale): string
    {
        return __('PDF generation failed', [], $locale);
    }
}
