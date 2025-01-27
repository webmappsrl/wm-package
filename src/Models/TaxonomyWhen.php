<?php

namespace Wm\WmPackage\Models;

use Illuminate\Support\Str;
use Illuminate\Support\MessageBag;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Models\Abstracts\Taxonomy;
use Illuminate\Validation\ValidationException;
use Wm\WmPackage\Traits\FeatureImageAbleModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class TaxonomyWhen extends Taxonomy
{

    protected function getRelationKey(): string
    {
        return 'whenable';
    }
}
