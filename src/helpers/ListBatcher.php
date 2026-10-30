<?php

namespace statikbe\linkchecker\helpers;

use craft\base\Batchable;

/**
 * A fixed list of items (ids, URLs) for a batched queue job.
 */
class ListBatcher implements Batchable
{
    /**
     * @param array<mixed> $items
     */
    public function __construct(private readonly array $items)
    {
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getSlice(int $offset, int $limit): iterable
    {
        return array_slice($this->items, $offset, $limit);
    }
}
