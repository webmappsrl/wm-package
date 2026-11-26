<?php

namespace Wm\WmPackage\Http\Requests\TrailRegistry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Wm\WmPackage\Http\Requests\TrailRegistry\Concerns\AuthorizesApiChannelApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

/**
 * Validazione del caricamento di un allegato su una domanda: la collection
 * deve essere una di quelle ammesse (vedi `collections()`) e il file e' obbligatorio.
 * Il contenuto del file (tipo, dimensione) non e' ancora vincolato: i formati
 * non sono concordati.
 */
class StoreAttachmentRequest extends FormRequest
{
    use AuthorizesApiChannelApplication;

    /**
     * Le collection che l'upload accetta: le registrate da
     * `TrailApplication::registerMediaCollections()` meno `original_geometry`.
     *
     * L'esclusione e' voluta, non una dimenticanza: la traccia viaggia nella
     * chiamata di creazione e li' si calcola anche il profilo altimetrico.
     * Se il canale potesse ricaricarla come allegato, sostituirebbe il file
     * senza che nulla ricalcoli i dati tecnici, e la domanda si ritroverebbe
     * con una traccia nuova e i numeri della vecchia: un disallineamento
     * silenzioso.
     *
     * @return list<string>
     */
    public static function collections(): array
    {
        return [
            ...array_values(array_diff(
                TrailApplication::SINGLE_FILE_COLLECTIONS,
                [TrailApplication::ORIGINAL_GEOMETRY_COLLECTION]
            )),
            TrailApplication::PHOTOS_COLLECTION,
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'collection' => ['required', 'string', Rule::in(static::collections())],
            'file' => ['required', 'file'],
        ];
    }

    /**
     * I nomi dei campi nei messaggi di errore sono quelli del payload.
     *
     * Laravel, lasciato fare, scrive «duration forward» al posto di
     * `duration_forward`: per una persona e' piu' leggibile, per chi integra e'
     * un nome che nel payload non esiste e che non si puo' cercare nella
     * documentazione. Qui ogni campo risponde con se stesso.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $names = array_map(
            fn (string $key) => explode('.', $key)[0],
            array_keys($this->rules()),
        );

        return array_combine($names, $names);
    }
}
