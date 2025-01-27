<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Wm\WmPackage\Models\TaxonomyWhere;

class TaxonomyWhereObserver extends AbstractObserver
{
    /**
     * Handle the TaxonomyWhere "creating" event.
     *
     * @return void
     */
    public function creating(Model $taxonomyWhere)
    {
        if ($taxonomyWhere->identifier != null) {
            $validateTaxonomyWhere = TaxonomyWhere::where('identifier', 'LIKE', $taxonomyWhere->identifier)->first();
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
    public function updating(TaxonomyWhere $taxonomyWhere)
    {
        if ($taxonomyWhere->identifier !== null) {
            $taxonomyWhere->identifier = Str::slug($taxonomyWhere->identifier, '-');
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
