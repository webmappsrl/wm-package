<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaxonomyObserver extends AbstractObserver
{
    /**
     * Handle the Taxonomy "creating" event.
     *
     * @return void
     */
    public function creating(Model $taxonomy)
    {
        $this->assignIdentifier($taxonomy);

        if ($taxonomy->identifier === null) {
            return;
        }

        $existing = $taxonomy::where('identifier', $taxonomy->identifier)->first();
        if ($existing !== null) {
            self::validationError("The inserted 'identifier' field already exists.");
        }
    }

    /**
     * Handle the Taxonomy "updating" event.
     *
     * @return void
     */
    public function updating(Model $taxonomy)
    {
        $this->assignIdentifier($taxonomy);
    }

    /**
     * Deriva (se assente) e normalizza l'identifier delegando la regola al
     * modello, che puo' sovrascriverla.
     *
     * Uno slug che si riduce a stringa vuota diventa null: la colonna e'
     * nullable e PostgreSQL ammette piu' NULL su un indice unique. Scriverlo
     * come stringa vuota, oltre a essere semanticamente sbagliato, faceva
     * saltare il check di unicita' (in PHP `'' != null` e' false).
     */
    private function assignIdentifier(Model $taxonomy): void
    {
        if (empty($taxonomy->identifier)) {
            $taxonomy->identifier = method_exists($taxonomy, 'generateIdentifier')
                ? $taxonomy->generateIdentifier()
                : null;
        }

        if ($taxonomy->identifier === null) {
            return;
        }

        $slug = Str::slug((string) $taxonomy->identifier, '-');
        $taxonomy->identifier = $slug !== '' ? $slug : null;
    }

    /**
     * @throws ValidationException
     */
    private static function validationError($message)
    {
        $messageBag = new MessageBag;
        $messageBag->add('error', __($message));

        throw ValidationException::withMessages($messageBag->getMessages());
    }
}
