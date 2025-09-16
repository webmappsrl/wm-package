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
        if ($taxonomy->identifier != null) {
            $validateTaxonomyWhere = $taxonomy::where('identifier', 'LIKE', $taxonomy->identifier)->first();
            if (! $validateTaxonomyWhere == null) {
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
        if ($taxonomy->identifier !== null) {
            $taxonomy->identifier = Str::slug($taxonomy->identifier, '-');
        }
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
