<?php

namespace statikbe\linkchecker\helpers;

use craft\base\Batchable;

/**
 * A fixed list of ids for a batched queue job.
 */
class IdBatcher implements Batchable
{
    /**
     * @param int[] $ids
     */
    public function __construct(private readonly array $ids)
    {
    }

    public function count(): int
    {
        return count($this->ids);
    }

    public function getSlice(int $offset, int $limit): iterable
    {
        return array_slice($this->ids, $offset, $limit);
    }
}
