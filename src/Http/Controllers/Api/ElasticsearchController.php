<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Illuminate\Http\Request;
use ONGR\ElasticsearchDSL\Aggregation\Bucketing\TermsAggregation;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use ONGR\ElasticsearchDSL\Query\FullText\MatchPhraseQuery;
use ONGR\ElasticsearchDSL\Query\FullText\MatchQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\RangeQuery;
use ONGR\ElasticsearchDSL\Search;
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
        $query = EcTrack::search($search, function (\Elastic\Elasticsearch\Client $client, Search $body) use ($search) {

            // # The es driver for Laravel Scout
            // # https://github.com/matchish/laravel-scout-elasticsearch?tab=readme-ov-file#search

            // # The package to build es request body with PHP
            // # https://github.com/handcraftedinthealps/ElasticsearchDSL/blob/7.x/docs/index.md
            // # https://github.com/handcraftedinthealps/ElasticsearchDSL/blob/7.x/docs/HowTo/HowToSearch.md

            // # The es query sintax
            // # https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-bool-query.html

            // # The es indices mappings and settings
            // # wm-package/config/wm-elasticsearch.php

            // Customize search to prioritize exact matches in name field

            $boolQuery = new BoolQuery;
            $boolQuery->add(new MatchPhraseQuery('name.phrase', $search, [
                'boost' => 10,
            ]), BoolQuery::SHOULD); // #OR
            $boolQuery->add(new MatchQuery('name.exact', $search, [
                'boost' => 5,
            ]), BoolQuery::SHOULD); // #OR
            $boolQuery->add(new MatchQuery('name.edge', $search, [
                'boost' => 4,
            ]), BoolQuery::SHOULD); // #OR

            // Replace the original query with our custom one
            $body->addQuery($boolQuery);

            // # Dump the es query body as array
            // dd($body->toArray());

            // // Create a custom query that prioritizes exact matches
            // $customQuery = [
            //     'bool' => [
            //         'should' => [
            //             // Highest priority: exact matches using phrase match on the name.phrase field
            //             ['match_phrase' => ['name.phrase' => ['query' => $search, 'boost' => 10]]],
            //             // Medium priority: exact matches on name.exact
            //             ['match' => ['name.exact' => ['query' => $search, 'boost' => 5]]],
            //             // Original query with default boosting
            //             $existingQuery
            //         ]
            //     ]
            // ];

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
        return $query->take(10000)->get();
    }
}
