<?php

namespace Wm\WmPackage\Http\Controllers\Api\TrailRegistry;

use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Wm\WmPackage\Http\Requests\TrailRegistry\ShowApplicationRequest;
use Wm\WmPackage\Http\Requests\TrailRegistry\StoreAttachmentRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

/**
 * Lettura di una domanda: i dati dichiarati cosi' come il Catasto li ha
 * registrati, gli allegati caricati, a che punto e' del procedimento, i dati
 * tecnici calcolati dal tracciato e, a domanda approvata, il numero del
 * sentiero.
 *
 * E' l'unico punto da cui chi chiama legge la domanda. Le chiamate che la
 * modificano rispondono solo com'e' andata l'azione: cosi' leggere non puo'
 * mai cambiare nulla, e si puo' interrogare quante volte si vuole.
 */
#[Group(
    name: 'Applications',
    description: 'Creazione e compilazione delle domande di accatastamento.',
    weight: 3,
)]
class ShowApplicationController extends Controller
{
    use ResolvesConfiguredApplication;

    /**
     * Legge la domanda
     *
     * Restituisce i dati dichiarati così come il Catasto li ha registrati, gli
     * allegati caricati, a che punto è la domanda, i dati tecnici calcolati dal
     * tracciato e, quando la domanda è approvata, il numero del sentiero.
     * `{application}` è l'`id` restituito dalla creazione; la chiamata non ha
     * corpo e non cambia nulla, quindi si può ripetere quante volte si vuole:
     * è la chiamata da usare per seguire una domanda nel tempo.
     *
     * ```
     * curl "$BASE_URL/trail-applications/$ID" \
     *   -H "Authorization: Bearer $TOKEN" \
     *   -H "Accept: application/json"
     * ```
     *
     * ### I dati dichiarati e gli allegati
     *
     * `submitted_data` restituisce il modulo inviato con la creazione, così
     * come il Catasto l'ha registrato: è il modo per verificare che cosa è
     * arrivato, campo per campo. I valori sono quelli inviati: un numero
     * mandato come testo torna come testo, i campi strutturati (`activities`,
     * `difficulty`, gli elenchi) tornano già decodificati. I dati non cambiano
     * dopo la creazione.
     *
     * `attachments` dice quanti file ci sono in ciascuna `collection` della
     * chiamata di caricamento: `0` vuol dire che quell'allegato manca. La
     * traccia non è fra questi, perché viaggia con la creazione.
     *
     * ### Lo stato
     *
     * - `under_compilation` — la domanda è stata creata ma non ancora
     *   confermata: si possono caricare gli allegati.
     * - `under_review` — confermata e in istruttoria.
     * - `approved` — approvata: `code` porta il numero del sentiero.
     * - `rejected` — respinta: `rejection_reason` porta il motivo scritto
     *   dall'operatore.
     *
     * ### Il numero del sentiero
     *
     * `code` è valorizzato solo quando la domanda è approvata, ed è il numero
     * assegnato al sentiero. In tutti gli altri stati è `null`: durante
     * l'istruttoria il numero può ancora cambiare, e mostrarlo prima lo
     * farebbe trattare come definitivo.
     *
     * ### I dati tecnici
     *
     * `dem_status` dice se i dati tecnici, calcolati dal tracciato, sono da
     * attendere. È `processing` quando il calcolo è ancora da fare,
     * `computed` altrimenti. Le unità sono quelle del payload: `distance` in
     * chilometri, dislivelli e quote in metri, `duration_forward` e
     * `duration_backward` in minuti interi (il format chiede metri e
     * `ORE:MINUTI`). È il modo per rileggere i dati tecnici quando alla
     * creazione il calcolo non era ancora finito.
     *
     * `processing` quando mancano i dati tecnici e la geometria è valida, o
     * quando i dati ci sono ma tutte le quote sono a zero. Con la geometria
     * assente o non valida il calcolo non può ripartire, e si risponde
     * `computed` con `dem_data` a `null`. Anche quando il server non riesce a
     * determinare lo stato del calcolo si risponde `computed` con `dem_data`
     * a `null`: i due casi non si distinguono dalla risposta.
     *
     * Dopo un calcolo riuscito i dati non sono mai vuoti, perché la
     * normalizzazione aggiunge sempre `duration_forward` e
     * `duration_backward`: se il servizio di calcolo non ha restituito nulla
     * di utile si riceve `computed` con tutti i valori a `null`, ed è la
     * verità, perché il calcolo è finito. Se ne ha restituiti solo alcuni si
     * restituisce ciò che c'è, senza promettere un completamento.
     *
     * `processing` è una promessa condizionata, non una certezza: il
     * riaccodamento dipende dal blocco preso alla creazione della domanda, che
     * dura un'ora, e riparte solo alla scadenza.
     */
    #[Response(status: 404, description: 'Domanda non trovata: l\'identificatore non esiste, oppure la domanda non è nata da questo canale. I due casi ricevono la stessa risposta.')]
    public function __invoke(ShowApplicationRequest $request, TrailApplication $application): JsonResponse
    {
        // La domanda nata d'ufficio e' gia' stata respinta come inesistente
        // da `ShowApplicationRequest::authorize()`.
        $application = $this->configuredApplication($application);
        [$demData, $needsDem] = $this->demOutcome($application);

        /**
         * Lo stato della domanda. `code` e `rejection_reason` ci sono sempre, e sono `null` finché la
         * domanda non è rispettivamente approvata o respinta.
         */
        return response()->json([
            /**
             * L'identificatore della domanda, lo stesso restituito dalla creazione.
             *
             * @var int
             */
            'id' => $application->getKey(),
            /**
             * Il modulo inviato con la creazione, così come il Catasto l'ha registrato: le stesse
             * chiavi dei parametri della creazione, `name` compreso, con i valori inviati. I campi
             * strutturati tornano decodificati. Non cambia dopo la creazione.
             *
             * @var array<string, mixed>
             */
            'submitted_data' => $this->submittedData($application),
            /**
             * Quanti file ci sono in ciascuna `collection` della chiamata di caricamento degli
             * allegati: `report`, `trail_data_sheet`, `authorizations`, `maintenance_plan`, `photos`.
             * `0` vuol dire che l'allegato manca. Le fotografie vanno da 0 a 5, gli altri da 0 a 1.
             *
             * @var array<string, int>
             */
            'attachments' => $this->attachmentCounts($application),
            /**
             * A che punto è la domanda. `under_compilation`: creata, non ancora confermata.
             * `under_review`: confermata e in istruttoria. `approved`: approvata, e `code` porta il
             * numero del sentiero. `rejected`: respinta, e `rejection_reason` porta il motivo.
             *
             * @var 'under_compilation'|'under_review'|'approved'|'rejected'
             */
            'status' => $application->status->value,
            /**
             * I dati tecnici calcolati dal tracciato: `distance` in chilometri, dislivelli e quote in
             * metri, `duration_forward` e `duration_backward` in minuti interi. È `null` finché il
             * calcolo non c'è: con `dem_status` `processing` arriverà, con `computed` non arriverà
             * (tracciato assente o non valido).
             *
             * @var array<string, mixed>|null
             */
            'dem_data' => $demData,
            /**
             * Se i dati tecnici sono da attendere. `processing`: il calcolo è in corso o in coda,
             * rileggere più tardi. `computed`: il calcolo è concluso, e ciò che c'è in `dem_data` è
             * definitivo.
             *
             * @var 'computed'|'processing'
             */
            'dem_status' => $needsDem ? 'processing' : 'computed',
            /**
             * Il numero del sentiero, per esempio `ZSUD318`. **È `null` in ogni stato diverso da
             * `approved`, e non è un errore**: la domanda ha un numero riservato già dalla
             * creazione, ma l'istruttoria può ancora cambiarlo, quindi non viene comunicato finché
             * la domanda non è approvata. Con `status` `approved` è sempre valorizzato.
             *
             * @var string|null
             */
            'code' => $this->approvedCode($application),
            /**
             * Il motivo del respingimento, scritto dall'operatore che ha istruito la domanda. **È
             * `null` in ogni stato diverso da `rejected`, e non è un errore**: una domanda non
             * respinta non ha un motivo. Con `status` `rejected` è sempre valorizzato.
             *
             * @var string|null
             */
            'rejection_reason' => $application->status === TrailApplicationStatus::Rejected
                ? $application->rejection_reason
                : null,
        ]);
    }

    /**
     * Il modulo come e' stato registrato: `api_data` senza la versione dello
     * schema, che e' un dato nostro, e con il nome, che sta in una colonna sua.
     *
     * @return array<string, mixed>
     */
    protected function submittedData(TrailApplication $application): array
    {
        $data = $application->api_data ?? [];
        unset($data[ApplicationSchema::VERSION_KEY]);

        return ['name' => $application->name] + $data;
    }

    /**
     * Le collection sono quelle che la chiamata di caricamento accetta, cosi'
     * chi legge ritrova gli stessi nomi che usa per caricare.
     *
     * @return array<string, int>
     */
    protected function attachmentCounts(TrailApplication $application): array
    {
        $counts = [];

        foreach (StoreAttachmentRequest::collections() as $collection) {
            $counts[$collection] = $application->getMedia($collection)->count();
        }

        return $counts;
    }

    /**
     * Il numero del sentiero, solo a domanda approvata.
     *
     * Prima dell'approvazione la domanda ha gia' un codice riservato, ma
     * l'istruttoria puo' ancora cambiarlo: restituirlo lo farebbe mostrare a
     * chi chiama come definitivo, salvo poi cambiare senza che nessuno se ne
     * accorga.
     */
    protected function approvedCode(TrailApplication $application): ?string
    {
        if ($application->status !== TrailApplicationStatus::Approved) {
            return null;
        }

        return $application->activeCode?->code;
    }

    /**
     * I dati tecnici da restituire e se il calcolo e' ancora da attendere.
     *
     * Il criterio e' `needsDem()`, lo stesso che decide se il calcolo viene
     * riaccodato: dichiarare «in elaborazione» altrove sarebbe una promessa
     * falsa (geometria assente o non valida: il job esce subito) o un esito
     * opposto (quote tutte a zero: il ricalcolo riparte). Si valuta su un
     * modello riletto, perche' `needsDem()` legge le proprieta' dalla memoria
     * e la geometria dal database, e su un modello stantio darebbe un esito
     * misto. I dati si leggono dallo stesso modello riletto: il calcolo scrive
     * in SQL e quello in memoria non li ha.
     *
     * «Presenti» vuol dire non vuoti: un risultato parziale si restituisce
     * com'e'. Se la rilettura fallisce si logga e si riprova il criterio sul
     * modello in mano, che ha la geometria dal database. Solo se anche quello
     * fallisce lo stato non si riesce a determinare, e si risponde `computed`
     * senza dati: e' l'opzione che non promette un calcolo che potrebbe non
     * arrivare mai. Per chi riceve e' indistinguibile dal caso di una
     * geometria assente o non valida.
     *
     * @return array{0: array<string, mixed>|null, 1: bool}
     */
    protected function demOutcome(TrailApplication $application): array
    {
        try {
            $fresh = $application->fresh();

            if ($fresh === null) {
                throw new RuntimeException('Istanza non rileggibile');
            }

            $demData = $fresh->properties['dem_data'] ?? null;

            return [empty($demData) ? null : (array) $demData, $fresh->needsDem()];
        } catch (Throwable $e) {
            Log::warning('trail_registry: esito del calcolo DEM non riletto alla lettura dello stato', [
                'application_id' => $application->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            return [null, $application->needsDem()];
        } catch (Throwable) {
            return [null, false];
        }
    }
}
