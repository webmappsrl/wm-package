<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Request;
use ONGR\ElasticsearchDSL\Aggregation\Bucketing\TermsAggregation;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use ONGR\ElasticsearchDSL\Query\FullText\MatchPhraseQuery;
use ONGR\ElasticsearchDSL\Query\FullText\MatchQuery;
use ONGR\ElasticsearchDSL\Query\FullText\QueryStringQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\RangeQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\RegexpQuery;
use ONGR\ElasticsearchDSL\Search;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Models\EcTrack;

class ElasticsearchController extends Controller
{
    public function index(Request $request)
    {

        try {
            $validated = $request->validate([
                'query' => 'string',
                'layer' => 'integer',
                'filters' => 'json',
                'app' => 'string|required',
                // IDS
                'ids' => ['json', 'nullable', function ($attribute, $value, $fail) {
                    // Verifica che sia un JSON valido
                    $decoded = json_decode($value, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        return $fail('Il campo '.$attribute.' deve essere un JSON valido.');
                    }

                    // Verifica che sia un array
                    if (! is_array($decoded)) {
                        return $fail('Il campo '.$attribute.' deve essere un array.');
                    }
                    // Verifica che ogni elemento sia un intero
                    foreach ($decoded as $id) {
                        if (! is_int($id)) {
                            return $fail('Tutti gli elementi in '.$attribute.' devono essere numeri interi.');
                        }
                    }
                }],

            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }

        // dd($validated->errors());

        $taxonomiesMapping = [
            'wheres' => 'taxonomyWheres',
            'activities' => 'taxonomyActivities',
        ];

        $query = $validated['query'] ?? '';
        $layer = $validated['layer'] ?? false;
        $filters = isset($validated['filters']) ? json_decode($validated['filters'], true) : [];
        $app = $validated['app'] ?? false;
        $ids = isset($validated['ids']) ? json_decode($validated['ids'], true) : [];

        $appId = (int) last(explode('_', $app));
        $search = str_replace('%20', ' ', $query);

        // $search = explode(' ', $search);
        // https://www.elastic.co/docs/reference/query-languages/query-dsl/query-dsl-query-string-query

        // $queryString = '';
        // if (count($search) > 1) {
        //     foreach ($search as $word) {
        //         $queryString .= "(name.keyword:$word OR name.edge:$word) AND ";
        //     }
        //     $queryString = rtrim($queryString, ' AND ');
        // }
        // dd($queryString);
        // https://github.com/matchish/laravel-scout-elasticsearch?tab=readme-ov-file#conditions
        // base query
        $query = EcTrack::search($search, function (\Elastic\Elasticsearch\Client $client, Search $body) use ($layer, $search, $ids) {

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

            // $boolQuery->add(new RegexpQuery('name.keyword', "$search.*", [
            //     'case_insensitive' => true
            // ]));

            // $boolQuery->add(new MatchPhraseQuery('name.phrase', $search, [
            //     'boost' => 10,
            // ]), BoolQuery::SHOULD); // #OR
            // $boolQuery->add(new MatchQuery('name.exact', $search, [
            //     'boost' => 5,
            // ]), BoolQuery::SHOULD); // #OR

            // $boolQuery->add(new MatchQuery('name.edge', $search, [
            //     'boost' => 4,
            // ]), BoolQuery::SHOULD); // #OR

            $boolQuery->add(new QueryStringQuery('*'.$search.'*', [
                'default_operator' => 'and',
            ]), BoolQuery::MUST); // #OR
            // $boolQuery->add(new MatchQuery('name.exact', $search, [
            //     'boost' => 5,
            // ]), BoolQuery::SHOULD); // #OR

            // $boolQuery->add(new MatchQuery('name.edge', $search, [
            //     'boost' => 4,
            // ]), BoolQuery::SHOULD); // #OR

            if (count($ids) > 0) {
                $boolQuery->add(new \ONGR\ElasticsearchDSL\Query\TermLevel\TermsQuery('id', $ids));
            }

            if ($layer) {
                $boolQuery->add(new \ONGR\ElasticsearchDSL\Query\TermLevel\TermsQuery('layers', [$layer]));
            } // #AND

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

            // dd($body->toArray()); // #DEBUG the whole es query body

            return $client->search(['index' => 'ec_tracks', 'body' => $body->toArray()])->asArray();
        })
            ->where('app_id', $appId); // #AND

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
        // return collect($query->orderBy('name.keyword', 'asc')->take(10000)->get()['hits'])->pluck('name');
        return $query->orderBy('name.keyword', 'asc')->take(10000)->get();
    }
}
