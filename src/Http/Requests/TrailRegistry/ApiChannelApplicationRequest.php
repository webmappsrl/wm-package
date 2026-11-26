<?php

namespace Wm\WmPackage\Http\Requests\TrailRegistry;

use Illuminate\Foundation\Http\FormRequest;
use Wm\WmPackage\Http\Requests\TrailRegistry\Concerns\AuthorizesApiChannelApplication;

/**
 * Una chiamata su una domanda esistente, senza payload: porta solo il
 * controllo di appartenenza al canale.
 *
 * La usano la conferma e la lettura dello stato, che agiscono sull'`id` della
 * rotta e non hanno un corpo da validare.
 */
abstract class ApiChannelApplicationRequest extends FormRequest
{
    use AuthorizesApiChannelApplication;

    // Niente `rules()`, nemmeno vuoto: Laravel senza il metodo valida con un
    // elenco vuoto, mentre Scramble, se lo trova, dichiara nella
    // documentazione una risposta `422` che queste chiamate non possono dare.
    // La conferma il suo `422` lo ha davvero, e lo dichiara a parte.
}
