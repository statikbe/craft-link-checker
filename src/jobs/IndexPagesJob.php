<?php

namespace statikbe\linkchecker\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\Query;
use craft\queue\BaseBatchedJob;
use statikbe\linkchecker\helpers\CursorBatcher;
use statikbe\linkchecker\LinkChecker;
use statikbe\linkchecker\services\Index;

/**
 * Reads the links of the pages that were saved (the pending table), in batches, after the editor's request. Only one job
 * runs at a time: pages saved meanwhile are read by this one or get a new job when it's done. After each batch, the checks
 * of new domains and external links are queued.
 */
class IndexPagesJob extends BaseBatchedJob
{
    public int $batchSize = 20;

    /** @var int the id of the last pending row read */
    public int $lastId = 0;

    /** @var int|null the pending pages when the job started */
    public ?int $total = null;

    protected function loadData(): Batchable
    {
        $index = LinkChecker::getInstance()->getIndex();
        $this->total ??= (int)(new Query())->from(Index::PENDING_TABLE)->count();
        return new CursorBatcher($this->total, fn(int $limit) => $index->pendingPages($this->lastId, $limit));
    }

    protected function beforeBatch(): void
    {
        LinkChecker::getInstance()->getIndex()->keepQueued();
    }

    protected function processItem(mixed $item): void
    {
        LinkChecker::getInstance()->getIndex()->indexPending($item);
        $this->lastId = (int)$item['id'];
    }

    protected function afterBatch(): void
    {
        LinkChecker::getInstance()->getIndex()->indexed();
    }

    protected function after(): void
    {
        LinkChecker::getInstance()->getIndex()->jobFinished();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('link-checker', 'Finding the links in the content');
    }
}
