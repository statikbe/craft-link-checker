<?php

namespace statikbe\linkchecker\helpers;

use craft\base\Batchable;

/**
 * Batches for a queue job that takes the next rows after a cursor (the id of the last row it handled) instead of carrying a
 * list: each batch queries its own rows, so the job's payload stays a few numbers. The total is fixed when the job starts;
 * when fewer rows are left (another job handled some), the job ends early.
 */
class CursorBatcher implements Batchable
{
    /**
     * @param int $total the number of rows when the job started
     * @param callable(int $limit): array<mixed> $next the next rows after the job's cursor
     */
    public function __construct(private int $total, private readonly mixed $next)
    {
    }

    public function count(): int
    {
        return $this->total;
    }

    public function getSlice(int $offset, int $limit): iterable
    {
        // One more than the batch, to know whether anything is left after it
        $rows = array_values(($this->next)($limit + 1));
        if (count($rows) <= $limit) {
            // Nothing left after these: the job ends with this batch
            $this->total = $offset + count($rows);
        }
        return array_slice($rows, 0, $limit);
    }
}
