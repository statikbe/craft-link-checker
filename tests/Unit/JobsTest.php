<?php

use statikbe\linkchecker\helpers\CursorBatcher;
use statikbe\linkchecker\jobs\CheckHostsJob;
use statikbe\linkchecker\jobs\CheckUrlsJob;
use statikbe\linkchecker\jobs\IndexPagesJob;
use statikbe\linkchecker\jobs\RebuildJob;
use statikbe\linkchecker\services\Checker;
use statikbe\linkchecker\services\Hosts;
use statikbe\linkchecker\services\Report;
use statikbe\linkchecker\services\Urls;

/** Runs a cursor over rows 1..$rows the way BaseBatchedJob does: batches until the offset reaches the total */
function runCursor(int $total, int $rows, int $batchSize, int $removedMeanwhile = 0): array
{
    $available = $rows > 0 ? range(1, $rows) : [];
    $lastId = 0;
    $offset = 0;
    $batches = [];
    do {
        $batcher = new CursorBatcher($total, function(int $limit) use (&$available, &$lastId) {
            return array_slice(array_values(array_filter($available, fn(int $id) => $id > $lastId)), 0, $limit);
        });
        $batch = [];
        foreach ($batcher->getSlice($offset, $batchSize) as $id) {
            $batch[] = $id;
            $lastId = $id;
            $offset++;
        }
        $batches[] = $batch;
        if ($removedMeanwhile && count($batches) === 1) {
            // Another job handled some of the rows that were due
            $available = array_slice($available, 0, count($available) - $removedMeanwhile);
        }
    } while ($offset < $batcher->count() && count($batches) < 100);
    return $batches;
}

it('takes the next rows after the cursor in each batch, without a list', function() {
    expect(runCursor(7, 7, 3))->toBe([[1, 2, 3], [4, 5, 6], [7]]);
});

it('ends a job early when fewer rows are left than when it started', function() {
    // 10 were due, 4 were checked by another job meanwhile: the job stops instead of spawning empty batches forever
    expect(runCursor(10, 10, 3, 4))->toBe([[1, 2, 3], [4, 5, 6]])
        ->and(runCursor(6, 0, 3))->toBe([[]]);
});

it('stops at the total when more rows became due meanwhile', function() {
    // The rest gets a new job (jobFinished() → queueIfDue())
    expect(runCursor(4, 9, 3))->toBe([[1, 2, 3], [4, 5, 6]]);
});

it('keeps the job payload to a few numbers', function() {
    foreach ([CheckUrlsJob::class, CheckHostsJob::class, IndexPagesJob::class, RebuildJob::class] as $class) {
        $public = array_map(fn(ReflectionProperty $p) => $p->getName(), (new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC));
        expect($public)->not->toContain('items')
            ->and($public)->not->toContain('hosts')
            ->and($public)->not->toContain('ids')
            ->and($public)->toContain('lastId')
            ->and($public)->toContain('total');
    }
});

it('checks unknown URLs less and less often', function() {
    expect(Urls::unknownInterval(1))->toBe('P1D')
        ->and(Urls::unknownInterval(2))->toBe('P3D')
        ->and(Urls::unknownInterval(3))->toBe('P7D')
        ->and(Urls::unknownInterval(4))->toBe('P14D')
        ->and(Urls::unknownInterval(40))->toBe('P14D');
});

it('looks domains up again a week after they were found, an hour after a first failure, a day after they were missing', function() {
    expect(Hosts::recheckInterval(true, 0))->toBe('P7D')
        ->and(Hosts::recheckInterval(true, 1))->toBe('PT1H')
        ->and(Hosts::recheckInterval(false, 2))->toBe('P1D');
});

it('only clears the report for changes that can change it', function() {
    $never = fn() => throw new RuntimeException('not asked');
    $linked = fn() => true;
    $unlinked = fn() => false;
    // Content edits don't matter; the URI, status and dates do
    expect(Report::changeMatters(true, false, true, ['title', 'fieldValues'], $never))->toBeFalse()
        ->and(Report::changeMatters(true, false, true, [], $never))->toBeFalse()
        ->and(Report::changeMatters(true, false, true, ['uri'], $never))->toBeTrue()
        ->and(Report::changeMatters(true, false, true, ['enabledForSite'], $never))->toBeTrue()
        ->and(Report::changeMatters(true, false, true, ['postDate'], $never))->toBeTrue()
        // Changes that aren't tracked (applying a draft) can't be told apart
        ->and(Report::changeMatters(true, false, true, null, $never))->toBeTrue()
        // A new page with a URL can make an own-site URL work; a new element without one can't
        ->and(Report::changeMatters(true, true, true, null, $never))->toBeTrue()
        ->and(Report::changeMatters(true, true, false, null, $never))->toBeFalse()
        // Deleted or restored: when it has a URL, or a link leads to it
        ->and(Report::changeMatters(false, false, true, null, $never))->toBeTrue()
        ->and(Report::changeMatters(false, false, false, null, $linked))->toBeTrue()
        ->and(Report::changeMatters(false, false, false, null, $unlinked))->toBeFalse();
});

it('puts block-mode errors on the blocks the control panel draws', function() {
    $page = (object)['uid' => 'page'];
    $row = (object)['uid' => 'row-1'];
    $cell = (object)['uid' => 'cell-1'];
    $drawnRow = (object)['uid' => 'row-1', 'drawn' => true];
    $drawn = ['contentBuilder' => ['row-1' => $drawnRow]];

    // A link in a column of a row: the row is replaced by the drawn one, the column stays
    $link = Checker::onDrawn(['chain' => [['element' => $page, 'field' => 'contentBuilder'], ['element' => $row, 'field' => 'gridCells']], 'element' => $cell], $drawn);
    expect($link['chain'][1]['element'])->toBe($drawnRow)
        ->and($link['chain'][0]['element'])->toBe($page)
        ->and($link['element'])->toBe($cell);

    // A link in a field of the row itself
    $link = Checker::onDrawn(['chain' => [['element' => $page, 'field' => 'contentBuilder']], 'element' => $row], $drawn);
    expect($link['element'])->toBe($drawnRow);

    // A link in a field of the page, or a block that isn't drawn: unchanged
    $own = ['chain' => [], 'element' => $page];
    expect(Checker::onDrawn($own, $drawn))->toBe($own);
    $other = ['chain' => [['element' => $page, 'field' => 'hero']], 'element' => $row];
    expect(Checker::onDrawn($other, $drawn))->toBe($other);
});
