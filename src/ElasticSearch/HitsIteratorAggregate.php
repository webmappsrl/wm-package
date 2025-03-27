<?php

namespace Wm\WmPackage\ElasticSearch;

use IteratorAggregate;
use Traversable;

class HitsIteratorAggregate implements IteratorAggregate
{
    public function __construct(protected array $results, protected $callback = null)
    {
        $this->results = $results;
        $this->callback = $callback;
    }

    public function getIterator(): Traversable
    {
        $this->results['hits'] = collect($this->results['hits']['hits'])->map(fn ($item) => $item['_source'])->toArray();

        return new \ArrayIterator($this->results);
    }
}
