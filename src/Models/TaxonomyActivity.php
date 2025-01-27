<?php

namespace Wm\WmPackage\Models;

use Wm\WmPackage\Models\Abstracts\Taxonomy;

class TaxonomyActivity extends Taxonomy
{
    protected function getRelationKey(): string
    {
        return 'activityable';
    }

    /**
     * Create a json for the activity
     */
    public function getJson(): array
    {
        $json = $this->toArray();

        unset($json['pivot']);
        unset($json['import_method']);
        unset($json['source']);
        unset($json['source_id']);
        unset($json['user_id']);

        foreach (array_keys($json) as $key) {
            if (is_null($json[$key])) {
                unset($json[$key]);
            }
        }

        return $json;
    }
}
