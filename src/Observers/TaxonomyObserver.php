<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaxonomyObserver extends AbstractObserver
{
    /**
     * Handle the TaxonomyWhere "creating" event.
     *
     * @return void
     */
    public function creating(Model $taxonomy)
    {
        if (empty($taxonomy->identifier)) {
            $taxonomy->identifier = self::generateIdentifierFromName($taxonomy);
        }

        if ($taxonomy->identifier != null) {
            $taxonomy->identifier = Str::slug($taxonomy->identifier, '-');

            $existing = $taxonomy::where('identifier', $taxonomy->identifier)->first();
            if ($existing !== null) {
                self::validationError("The inserted 'identifier' field already exists.");
            }
        }
    }

    /**
     * Handle the TaxonomyWhere "deleted" event.
     *
     * @return void
     */
    public function updating(Model $taxonomy)
    {
        if (empty($taxonomy->identifier)) {
            $taxonomy->identifier = self::generateIdentifierFromName($taxonomy);
        }

        if ($taxonomy->identifier !== null) {
            $taxonomy->identifier = Str::slug($taxonomy->identifier, '-');
        }
    }

    /**
     * Genera un identifier dallo slug del nome del modello, scegliendo la prima
     * traduzione disponibile (preferenza: locale corrente, poi 'it', poi qualunque).
     */
    private static function generateIdentifierFromName(Model $taxonomy): ?string
    {
        if (! method_exists($taxonomy, 'getTranslations')) {
            return null;
        }

        $translations = $taxonomy->getTranslations('name');
        $candidates = [
            app()->getLocale(),
            'it',
            'en',
        ];

        foreach ($candidates as $locale) {
            if (! empty($translations[$locale])) {
                return $translations[$locale];
            }
        }

        foreach ($translations as $value) {
            if (! empty($value)) {
                return $value;
            }
        }

        return null;
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
