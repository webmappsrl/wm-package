<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Models\Abstracts\Taxonomy;
use Wm\WmPackage\Traits\FeatureImageAbleModel;

class TaxonomyPoiType extends Taxonomy
{


    protected function getRelationKey(): string
    {
        return 'poi_typeable';
    }

    /**
     * Create a json for the activity
     */
    public function getJson(): array
    {
        $json = $this->toArray();

        $data = [];

        $data['id'] = $json['id'];

        $data['name'] = $json['name'];
        if ($data['name']) {
            foreach ($data['name'] as $lang => $val) {
                if (empty($val) || ! $val) {
                    unset($data['name'][$lang]);
                }
            }
        }
        if ($json['description']) {
            foreach ($json['description'] as $lang => $val) {
                if (! empty($val) || $val) {
                    $data['description'][$lang] = $val;
                }
            }
        }

        $data['color'] = $json['color'];
        $data['icon'] = $json['icon'];

        return $data;
    }
}
