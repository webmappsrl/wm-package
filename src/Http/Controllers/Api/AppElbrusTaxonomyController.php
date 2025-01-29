<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Models\TaxonomyTarget;
use Wm\WmPackage\Models\TaxonomyTheme;
use Wm\WmPackage\Models\TaxonomyWhen;
use Wm\WmPackage\Models\TaxonomyWhere;

class AppElbrusTaxonomyController extends Controller
{
    private array $names = [
        'activity',
        'where',
        'when',
        'who',
        'theme',
        'webmapp_category',
    ];

    public function getTerms(App $app, string $taxonomy_name): JsonResponse
    {
        $json = [];
        $code = 200;
        if (! in_array($taxonomy_name, $this->names)) {
            $code = 400;
            $json = ['code' => $code, 'error' => 'Taxonomy name not valid'];

            return response()->json($json, $code);
        }

        $terms = $this->_termsByUserId($app, $taxonomy_name);

        if (count($terms) > 0) {
            foreach ($terms as $tid => $items) {
                $tax = $this->getTaxonomyModelByIdAndName($taxonomy_name, $tid);
                $tax = $tax->toArray();
                $tax['items'] = $items;
                $tax['id'] = $taxonomy_name . '_' . $tid;
                $json[$taxonomy_name . '_' . $tid] = $tax;
            }
        }

        return response()->json($json, $code);
    }

    private function _termsByUserId($app, $taxonomy_name)
    {
        $terms = [];
        $add_poi_types = false;
        switch ($taxonomy_name) {
            case 'activity':
                $table = 'taxonomy_activityables';
                $tid = 'taxonomy_activity_id';
                $fid = 'taxonomy_activityable_id';
                $type = 'taxonomy_activityable_type';
                break;
            case 'theme':
                $table = 'taxonomy_themeables';
                $tid = 'taxonomy_theme_id';
                $fid = 'taxonomy_themeable_id';
                $type = 'taxonomy_themeable_type';
                break;
            case 'who':
                $table = 'taxonomy_targetables';
                $tid = 'taxonomy_target_id';
                $fid = 'taxonomy_targetable_id';
                $type = 'taxonomy_targetable_type';
                break;
            case 'when':
                $table = 'taxonomy_whenables';
                $tid = 'taxonomy_when_id';
                $fid = 'taxonomy_whenable_id';
                $type = 'taxonomy_whenable_type';
                break;
            case 'where':
                $table = 'taxonomy_whereables';
                $tid = 'taxonomy_where_id';
                $fid = 'taxonomy_whereable_id';
                $type = 'taxonomy_whereable_type';
                break;
            case 'webmapp_category':
                $table = 'taxonomy_poi_typeables';
                $tid = 'taxonomy_poi_type_id';
                $fid = 'taxonomy_poi_typeable_id';
                $type = 'taxonomy_poi_typeable_type';
                $add_poi_types = true;
                break;
            default:
                $table = 'taxonomy_ables';
                $tid = 'taxonomy_id';
                $fid = 'taxonomy_able_id';
                $type = 'taxonomy_able_type';
        }
        $res = DB::select("
            SELECT $tid as tid, $fid as fid
            FROM $table
            WHERE $type LIKE '%\Models\EcTrack'
            AND $fid IN (select id from ec_tracks where user_id=$app->user_id)
         ");
        if (count($res) > 0) {
            foreach ($res as $item) {
                $terms[$item->tid]['track'][] = 'ec_track_' . $item->fid;
            }
        }

        if ($add_poi_types) {
            //TODO: use relation instead of raw model query
            $res = DB::select("
                SELECT $tid as tid, $fid as fid
                FROM $table
                WHERE $type LIKE '%\Models\EcPoi'
                AND $fid IN (
                    SELECT id FROM ec_pois
                );
            ");

            if (count($res) > 0) {
                foreach ($res as $item) {
                    $terms[$item->tid]['poi'][] = 'ec_poi_' . $item->fid;
                }
            }
        }

        return $terms;
    }

    /**
     * Update the specified user.
     */
    public function getTracksByAppAndTerm(App $app, string $taxonomy_name, int $term_id): JsonResponse
    {
        $json = [];
        $code = 200;

        $json['tracks'] = [];

        if (! in_array($taxonomy_name, $this->names)) {
            $code = 400;
            $json = ['code' => $code, 'error' => 'Taxonomy name not valid'];

            return response()->json($json, $code);
        }

        $term = $this->_getTermByTaxonomy($taxonomy_name, $term_id);
        if (is_null($term)) {
            $code = 404;
            $json = ['code' => $code, 'Term NOT found in taxonomy ' . $taxonomy_name];

            return response()->json($json, $code);
        }

        $tracks = $app->listTracksByTerm($term, $taxonomy_name);

        return response()->json($tracks, $code);
    }

    protected function _getTermByTaxonomy(string $taxonomy_name, int $term_id)
    {
        $term = $this->getTaxonomyModelByIdAndName($taxonomy_name, $term_id);

        $tax = null;
        if ($term) {
            $tax = $term->toArray();
        }

        return $tax;
    }

    protected function getTaxonomyModelByIdAndName(string $taxonomyName, int $modelId)
    {
        switch ($taxonomyName) {
            case 'activity':
                $model = TaxonomyActivity::find($modelId);
                break;
            case 'theme':
                $model = TaxonomyTheme::find($modelId);
                break;
            case 'where':
                $model = TaxonomyWhere::find($modelId);
                unset($model['geometry']);
                break;
            case 'who':
                $model = TaxonomyTarget::find($modelId);
                break;
            case 'when':
                $model = TaxonomyWhen::find($modelId);
                break;
            case 'webmapp_category':
                $model = TaxonomyPoiType::find($modelId);
                break;
        }

        return $model;
    }
}
