<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\Request;
use ONGR\ElasticsearchDSL\Aggregation\Bucketing\TermsAggregation;
use ONGR\ElasticsearchDSL\Query\TermLevel\RangeQuery;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Models\EcTrack;

class ElasticsearchController extends Controller
{
    public function index(Request $request)
    {

        $validated = $request->validate([
            'query' => 'string',
            'layer' => 'integer',
            'filters' => 'json',
            'app' => 'string|required',
        ]);

        $taxonomiesMapping = [
            'wheres' => 'taxonomyWheres',
            'activities' => 'taxonomyActivities',
        ];

        $query = $validated['query'] ?? '';
        $layer = $validated['layer'] ?? false;
        $filters = isset($validated['filters']) ? json_decode($validated['filters'], true) : [];
        $app = $validated['app'] ?? false;

        $appId = (int) last(explode('_', $app));
        $search = str_replace('%20', ' ', $query);

        // https://github.com/matchish/laravel-scout-elasticsearch?tab=readme-ov-file#conditions

        // base query
        $query = EcTrack::search($search, function (\Elastic\Elasticsearch\Client $client, $body) {

            $themesAggregation = new TermsAggregation('taxonomyWheres');
            $themesAggregation->setField('taxonomyWheres');

            $activitiesAggregation = new TermsAggregation('taxonomyActivities');
            $activitiesAggregation->setField('taxonomyActivities');

            $body->addAggregation($activitiesAggregation);
            $body->addAggregation($themesAggregation);

            return $client->search(['index' => 'ec_tracks', 'body' => $body->toArray()])->asArray();
        })->where('app_id', $appId);

        // handle layer
        if ($layer) {
            $query->whereIn('layers', $layer);
        }

        // handle filters
        if (count($filters) > 0) {
            foreach ($filters as $filter) {

                if (! array_key_exists('identifier', $filter)) {
                    continue;
                } // skip filters without identifier

                $identifier = $filter['identifier'];
                // handle taxonomy filter
                if (array_key_exists('taxonomy', $filter) && isset($taxonomiesMapping[$filter['taxonomy']])) {
                    $query->where($taxonomiesMapping[$filter['taxonomy']], $identifier);
                }
                // handle range filter
                elseif (array_key_exists('min', $filter) && array_key_exists('max', $filter)) {
                    $query->where($identifier, new RangeQuery($identifier, [
                        RangeQuery::GTE => $filter['min'],
                        RangeQuery::LTE => $filter['max'],
                    ]));
                }
            }
        }

        // results are formatted in wm-package/src/ElasticSearch/HitsIteratorAggregate.php
        return $query->get();
    }
}
