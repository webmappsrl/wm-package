<?php

namespace Wm\WmPackage\Http\Controllers\Api\TrailRegistry;

use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Wm\WmPackage\Http\Requests\TrailRegistry\ConfirmApplicationRequest;
use Wm\WmPackage\TrailRegistry\Exceptions\ApplicationIncompleteException;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailApplicationTransitionException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * Conferma della compilazione: lo sportello dichiara concluso il fascicolo e
 * lo trasmette all'istruttoria.
 */
#[Group(
    name: 'Applications',
    description: 'Creazione e compilazione delle domande di accatastamento.',
    weight: 3,
)]
class ConfirmApplicationController extends Controller
{
    use ResolvesConfiguredApplication;

    public function __construct(protected TrailRegistryService $registry) {}

    /**
     * Conferma la compilazione
     *
     * Dichiara conclusa la compilazione e trasmette la domanda
     * all'istruttoria. `{application}` è l'`id` restituito dalla creazione; la
     * chiamata non ha corpo.
     *
     * Si può fare solo se il fascicolo è completo: oltre ai campi del modulo e
     * alla traccia (campo 68 del format, già nella chiamata di creazione)
     * servono gli allegati `report` (campo 67), `trail_data_sheet` (campo 69),
     * `photos` (campo 70, almeno una) e `maintenance_plan` (campo 72).
     * `authorizations` (campo 71) è facoltativo. «Campo N del format» rinvia
     * alla colonna «N. campo» del foglio «Format istanza accatastamento V» (il
     * collegamento è nell'introduzione di questa documentazione).
     *
     * Se qualcosa manca la risposta è `422` e `missing` elenca cosa: i campi
     * per identificatore (gli stessi nomi dei parametri della creazione) e gli
     * allegati per nome della collection, così che chi chiama possa segnalare
     * l'errore accanto al campo giusto. La domanda resta in compilazione e gli
     * allegati mancanti si possono caricare, poi si conferma di nuovo. Un
     * campo mancante invece non si integra: i dati di una domanda creata non
     * si modificano, e la domanda va rifatta. La creazione rifiuta già un
     * modulo senza i campi obbligatori, quindi in pratica manca un allegato.
     *
     * La chiamata è ripetibile: su una domanda già trasmessa risponde `200`
     * come la prima volta, perché chi ritenta dopo un timeout di rete non deve
     * credere di non aver trasmesso. `already_confirmed` distingue i due casi:
     * `false` se la domanda è stata trasmessa da questa chiamata, `true` se lo
     * era già. Fra due conferme contemporanee della stessa domanda una sola
     * riceve `false`.
     *
     * ```
     * curl -X POST "$BASE_URL/trail-applications/$ID/confirm" \
     *   -H "Authorization: Bearer $TOKEN" \
     *   -H "Accept: application/json"
     * ```
     *
     * ### Cosa non restituisce
     *
     * La risposta dice com'è andata la conferma, non com'è la domanda. Lo
     * stato, i dati tecnici calcolati dal tracciato e, una volta approvata, il
     * numero del sentiero si leggono da `GET /trail-applications/{id}`, che si
     * può interrogare quante volte si vuole senza cambiare nulla.
     */
    #[Response(status: 404, description: 'Domanda non trovata: l\'identificatore non esiste, oppure la domanda non è nata da questo canale. I due casi ricevono la stessa risposta.')]
    #[Response(status: 409, description: 'La domanda è già stata decisa (respinta o approvata) e non si può ritrasmettere.', type: 'array{error: string}')]
    #[Response(status: 422, description: 'Fascicolo incompleto: `missing.fields` elenca i campi mancanti, `missing.attachments` le collection di allegati mancanti.', type: 'array{error: string, missing: array{fields: string[], attachments: string[]}}')]
    public function __invoke(ConfirmApplicationRequest $request, TrailApplication $application): JsonResponse
    {
        // La domanda nata d'ufficio e' gia' stata respinta come inesistente
        // da `ConfirmApplicationRequest::authorize()`.
        $application = $this->configuredApplication($application);
        try {
            $confirmedNow = $this->registry->confirmCompilation($application);
        } catch (ApplicationIncompleteException $e) {
            return response()->json([
                'error' => __('The application is incomplete and cannot be submitted: see the missing fields and attachments'),
                'missing' => $e->missing(),
            ], 422);
        } catch (InvalidTrailApplicationTransitionException) {
            return response()->json([
                'error' => __('The application has already been decided and can no longer be submitted'),
            ], 409);
        }

        /**
         * Domanda trasmessa all'istruttoria, da questa chiamata o da una precedente.
         */
        return response()->json([
            /**
             * L'identificatore della domanda.
             *
             * @var int
             */
            'id' => $application->getKey(),
            /**
             * Sempre `under_review`: la domanda è in istruttoria. Lo stato successivo — approvata o
             * respinta — si legge dalla chiamata di lettura dello stato.
             *
             * @var 'under_review'
             */
            'status' => $application->status->value,
            /**
             * `false` se la domanda è stata trasmessa da questa chiamata, `true` se lo era già da una
             * chiamata precedente. In entrambi i casi la domanda è in istruttoria e non c'è nulla da
             * ripetere: `true` è la risposta normale a un ritentativo dopo un timeout di rete.
             *
             * @var bool
             */
            'already_confirmed' => ! $confirmedNow,
        ]);
    }
}
