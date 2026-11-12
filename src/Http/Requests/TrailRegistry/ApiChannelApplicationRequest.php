<?php

namespace Wm\WmPackage\Http\Requests\TrailRegistry;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * Una chiamata su una domanda esistente, senza payload: porta solo il
 * controllo di appartenenza al canale.
 *
 * La usano la conferma e la lettura dello stato, che agiscono sull'`id` della
 * rotta e non hanno un corpo da validare.
 */
abstract class ApiChannelApplicationRequest extends FormRequest
{
    /**
     * Una domanda che non e' di questo canale, per chi chiama, non esiste.
     *
     * Stessa regola e stessa ragione di `StoreAttachmentRequest::authorize()`:
     * si solleva la stessa eccezione del route model binding, cosi' una
     * domanda d'ufficio e un identificatore sconosciuto escono dallo stesso
     * punto dell'handler con la stessa risposta, e non si possono distinguere
     * scorrendo gli identificatori.
     *
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

    // Niente `rules()`, nemmeno vuoto: Laravel senza il metodo valida con un
    // elenco vuoto, mentre Scramble, se lo trova, dichiara nella
    // documentazione una risposta `422` che queste chiamate non possono dare.
    // La conferma il suo `422` lo ha davvero, e lo dichiara a parte.
}
