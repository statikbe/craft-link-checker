<?php

namespace statikbe\linkchecker\jobs;

use Craft;
use craft\base\Batchable;
use craft\queue\BaseBatchedJob;
use statikbe\linkchecker\helpers\CursorBatcher;
use statikbe\linkchecker\LinkChecker;

/**
 * Checks the external URLs that are due (Urls), in batches. Each batch takes the next due URLs after the last one it
 * checked, so the job carries no list. Only one job runs at a time: URLs that become due meanwhile are taken by this one or
 * get a new job when it's done.
 */
class CheckUrlsJob extends BaseBatchedJob
{
    public int $batchSize = 20;

    /** @var int the id of the last URL checked */
    public int $lastId = 0;

    /** @var int|null the URLs that were due when the job started */
    public ?int $total = null;

    protected function loadData(): Batchable
    {
        $urls = LinkChecker::getInstance()->getUrls();
        $this->total ??= (int)$urls->dueQuery()->count();
        return new CursorBatcher($this->total, fn(int $limit) => $urls->due(false, $this->lastId, $limit));
    }

    protected function beforeBatch(): void
    {
        LinkChecker::getInstance()->getUrls()->keepQueued();
    }

    protected function processItem(mixed $item): void
    {
        LinkChecker::getInstance()->getUrls()->checkOne($item);
        $this->lastId = (int)$item['id'];
    }

    protected function after(): void
    {
        $plugin = LinkChecker::getInstance();
        $plugin->getUrls()->jobFinished();
        $plugin->prune();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('link-checker', 'Checking external links');
    }
}
