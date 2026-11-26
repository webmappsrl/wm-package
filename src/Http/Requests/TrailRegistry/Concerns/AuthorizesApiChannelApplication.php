<?php

namespace Wm\WmPackage\Http\Requests\TrailRegistry\Concerns;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * Una domanda che non e' di questo canale, per chi chiama non esiste.
 *
 * Il controllo sta in `authorize()` e non nel controller perche' `authorize()`
 * gira prima delle regole: nel controller ci si arriverebbe solo con un
 * payload valido, e con uno non valido una domanda d'ufficio darebbe 422
 * mentre un identificatore sconosciuto darebbe 404 — due risposte diverse che
 * permetterebbero di scorrere gli identificatori e capire quali esistono.
 *
 * Si solleva la stessa eccezione del route model binding, cosi' le due
 * risposte escono dallo stesso punto dell'handler e sono identiche parola per
 * parola.
 *
 * Vive in un trait perche' serve a chiamate che non hanno nient'altro in
 * comune: quelle senza payload estendono `ApiChannelApplicationRequest`,
 * quelle con un payload portano le proprie regole e non possono ereditare
 * anche da li'. Duplicarlo significherebbe che una correzione futura ne
 * aggiorna una copia sola, su un comportamento che esiste per non far
 * distinguere una domanda altrui da una inesistente.
 */
trait AuthorizesApiChannelApplication
{
    /**
     * @throws ModelNotFoundException se la domanda non e' nata dal canale API
     */
    public function authorize(): bool
    {
        $application = $this->route('application');

        if ($application instanceof TrailApplication && $application->source !== 'api') {
            throw (new ModelNotFoundException)->setModel(TrailApplication::class, [$application->getKey()]);
        }

        return true;
    }
}
