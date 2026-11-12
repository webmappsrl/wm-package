<?php

namespace Wm\WmPackage\Http\Controllers\Api\TrailRegistry;

use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;
use Wm\WmPackage\Http\Requests\TrailRegistry\StoreAttachmentRequest;
use Wm\WmPackage\Services\Models\UgcMediaHashService;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * Caricamento di un allegato su una domanda di accatastamento gia' creata.
 */
#[Group(
    name: 'Applications',
    description: 'Creazione e compilazione delle domande di accatastamento.',
    weight: 3,
)]
class StoreAttachmentController extends Controller
{
    use ResolvesConfiguredApplication;

    /**
     * Fotografie distinte ammesse su una domanda (campo 70 del format).
     */
    public const MAX_PHOTOS = 5;

    /**
     * Carica un allegato
     *
     * Aggiunge un allegato alla domanda creata con `POST /trail-applications`,
     * **uno per chiamata**, come `multipart/form-data`. `{application}` è l'`id`
     * restituito dalla creazione.
     *
     * «Campo N del format» rinvia alla colonna «N. campo» del foglio
     * «Format istanza accatastamento V» (il collegamento è nell'introduzione
     * di questa documentazione). `collection` dice dove va l'allegato:
     *
     * | `collection` | Campo del format | Note |
     * |---|---|---|
     * | `report` | 67 — *Relazione descrittiva* | obbligatoria |
     * | `trail_data_sheet` | 69 — *Tabella dati sentiero* | obbligatoria |
     * | `photos` | 70 — *Documentazione fotografica* | obbligatoria, al massimo cinque fotografie distinte |
     * | `authorizations` | 71 — *Eventuali autorizzazioni* | facoltativa |
     * | `maintenance_plan` | 72 — *Piano Manutenzione* | obbligatoria |
     *
     * Il campo 68 (*Traccia GPS/cartografia digitale*) non è fra queste: la
     * traccia viaggia nella chiamata di creazione, e qui è rifiutata.
     *
     * Le collection documentali tengono un file solo: rimandarlo lo
     * sostituisce, quindi una chiamata ripetuta dopo un timeout è innocua. Le
     * fotografie sono al massimo cinque e si contano dopo aver riconosciuto
     * quelle già arrivate: rimandare la stessa immagine non ne aggiunge una
     * seconda né conta contro il limite, e risponde `201` come la prima volta.
     * Una sesta fotografia diversa è rifiutata.
     *
     * Si carica finché la domanda è in compilazione: dopo la conferma risponde
     * `409`.
     *
     * ```
     * curl -X POST "$BASE_URL/trail-applications/$ID/attachments" \
     *   -H "Authorization: Bearer $TOKEN" \
     *   -H "Accept: application/json" \
     *   -F "collection=report" \
     *   -F "file=@relazione.pdf"
     * ```
     *
     * ### La traccia non si carica qui
     *
     * La traccia del sentiero (campo 68 del format, *Traccia GPS/cartografia
     * digitale*) è obbligatoria e **viaggia nella chiamata di creazione**, nel
     * parametro `geometry`, non con gli allegati: serve subito, perché la
     * creazione restituisce i dati tecnici calcolati dal tracciato. Per questo
     * questo endpoint non la accetta. Gli allegati che si caricano qui sono gli
     * altri, dal campo 67 al 72.
     */
    #[BodyParameter('collection', description: 'Dove va l\'allegato: `report` (campo 67), `trail_data_sheet` (campo 69), `photos` (campo 70), `authorizations` (campo 71), `maintenance_plan` (campo 72). `original_geometry`, cioè la traccia (campo 68), non è ammessa.')]
    #[BodyParameter('file', description: 'Il documento. Un file per chiamata.')]
    #[Response(status: 404, description: 'Domanda non trovata: l\'identificatore non esiste, oppure la domanda non è nata da questo canale. I due casi ricevono la stessa risposta.')]
    #[Response(status: 409, description: 'La domanda è già stata trasmessa e non accetta più allegati.', type: 'array{error: string}')]
    #[Response(status: 422, description: 'Richiesta non valida (collection non ammessa, file mancante) oppure fotografie distinte già al massimo.', type: 'array{error: string}|array{message: string, errors: array<string, string[]>}')]
    public function __invoke(StoreAttachmentRequest $request, TrailApplication $application): JsonResponse
    {
        // La domanda nata d'ufficio e' gia' stata respinta come inesistente
        // da `StoreAttachmentRequest::authorize()`, prima della validazione.
        $application = $this->configuredApplication($application);
        $collection = $request->validated('collection');
        $file = $request->file('file');

        // Il file su disco non partecipa al rollback: se la transazione
        // fallisce dopo che e' stato salvato, la riga del media sparisce ma il
        // file resterebbe, e nessuno lo ritroverebbe. Si tiene il media in
        // `$stored` e lo si toglie nel catch.
        $stored = null;

        try {
            $outcome = DB::transaction(function () use ($application, $collection, $file, &$stored) {
                // Lo stato si rilegge sotto lock: la conferma puo' girare in
                // parallelo (il canale fa piu' chiamate ravvicinate) e fra un
                // controllo fatto prima e la scrittura la domanda potrebbe
                // essere stata trasmessa, con l'allegato in un fascicolo
                // chiuso. Il lock e' lo stesso di `confirmCompilation()`.
                $locked = $application->newQuery()->whereKey($application->getKey())->lockForUpdate()->first();

                if ($locked === null) {
                    throw (new ModelNotFoundException)->setModel(TrailApplication::class, [$application->getKey()]);
                }

                // Dopo la trasmissione il fascicolo e' chiuso. Si distingue da
                // «inesistente»: la domanda e' del chiamante e lo stato e' un
                // fatto che puo' conoscere.
                if ($locked->status !== TrailApplicationStatus::UnderCompilation) {
                    return 'closed';
                }

                if ($collection === TrailApplication::PHOTOS_COLLECTION) {
                    return $this->storePhoto($application, $file, $stored);
                }

                // Collection a file singolo: un reinvio sostituisce, non affianca.
                $stored = $application->addMedia($file->getRealPath())
                    ->preservingOriginal()
                    ->usingFileName($file->getClientOriginalName())
                    ->toMediaCollection($collection);

                return 'stored';
            });
        } catch (Throwable $e) {
            $this->discardStoredFile($stored);

            throw $e;
        }

        return match ($outcome) {
            'closed' => $this->failure(__('The application has already been submitted and no longer accepts attachments'), 409),
            'limit' => $this->failure(__('The application already has the maximum of :max different photos', ['max' => self::MAX_PHOTOS]), 422),
            default => $this->stored($application, $collection),
        };
    }

    /**
     * Aggiunge una fotografia rispettando il limite, che si conta dopo la
     * deduplicazione: una fotografia gia' presente non e' un'aggiunta, quindi
     * il rinvio della quinta dopo un timeout passa (e' il caso per cui la
     * deduplicazione esiste), mentre una sesta distinta no.
     *
     * Gira dentro la transazione che ha preso il lock sulla riga della
     * domanda: senza, due chiamate in parallelo con la quarta e la quinta
     * fotografia leggerebbero entrambe «ne ho quattro» e arriverebbero a sei.
     *
     * @return 'stored'|'limit'
     */
    protected function storePhoto(TrailApplication $application, UploadedFile $file, ?Media &$stored): string
    {
        // La relazione puo' essere gia' stata caricata prima del lock.
        $application->unsetRelation('media');

        $hashes = UgcMediaHashService::make();
        $isKnown = $hashes->findByContent(
            $application,
            $hashes->hashOfFile($file->getRealPath()),
            TrailApplication::PHOTOS_COLLECTION
        ) !== null;

        if (! $isKnown && $application->getMedia(TrailApplication::PHOTOS_COLLECTION)->count() >= self::MAX_PHOTOS) {
            return 'limit';
        }

        // Se e' gia' presente, addPhoto() la restituisce senza aggiungere: in
        // quel caso non c'e' niente da togliere in caso di errore.
        $photo = $application->addPhoto($file);
        $stored = $isKnown ? null : $photo;

        return 'stored';
    }

    protected function stored(TrailApplication $application, string $collection): JsonResponse
    {
        // Il conteggio si rilegge dal database: la relazione in memoria puo'
        // essere precedente all'ultima scrittura.
        $application->unsetRelation('media');

        /**
         * Allegato registrato.
         *
         * @status 201
         */
        return response()->json([
            /**
             * La collection in cui l'allegato è stato registrato, la stessa indicata nella chiamata.
             *
             * @var 'report'|'trail_data_sheet'|'photos'|'authorizations'|'maintenance_plan'
             */
            'collection' => $collection,
            /**
             * Quanti file ci sono ora nella collection. Per i documenti è sempre 1: un nuovo
             * caricamento sostituisce il precedente. Per le fotografie va da 1 a 5, e una fotografia
             * identica a una già caricata non viene contata due volte: se `count` non cresce, il file
             * c'era già.
             *
             * @var int
             */
            'count' => $application->getMedia($collection)->count(),
        ], 201);
    }

    /**
     * Toglie dal disco il file salvato da una transazione andata in rollback.
     *
     * Lo toglie solo se la riga del media non esiste piu': `DB::transaction()`
     * esegue i callback `afterCommit` dopo il COMMIT vero, e se uno di essi
     * solleva l'eccezione arriva qui con l'allegato gia' committato. Cancellare
     * il file lascerebbe una riga che punta al nulla su una domanda valida. Se
     * la lettura stessa fallisce non si sa, e si lascia il file: un orfano e'
     * meno grave di un file tolto a una domanda esistente.
     *
     * Non solleva: chi chiama sta gia' propagando l'errore vero.
     */
    protected function discardStoredFile(?Media $media): void
    {
        if ($media === null) {
            return;
        }

        try {
            if ($media->newQuery()->whereKey($media->getKey())->exists()) {
                return;
            }

            app(Filesystem::class)->removeAllFiles($media);
        } catch (Throwable $e) {
            Log::warning('trail_registry: file dell\'allegato annullato non rimosso', [
                'media_id' => $media->getKey(),
                'disk' => $media->disk,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function failure(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}
