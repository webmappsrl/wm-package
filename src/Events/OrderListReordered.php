<?php

namespace Wm\WmPackage\Events;

class OrderListReordered
{
    /**
     * @param  class-string  $modelClass
     * @param  array<int>  $ids
     */
    public function __construct(
        public string $modelClass,
        public string $scopeColumn,
        public string $scopeValue,
        public string $orderColumn,
        public array $ids
    ) {}
}

