<?php

namespace statikbe\linkchecker\jobs;

use Craft;
use craft\base\Batchable;
use craft\queue\BaseBatchedJob;
use statikbe\linkchecker\helpers\ListBatcher;
use statikbe\linkchecker\LinkChecker;

/**
 * Checks the external URLs that are due (Urls), in batches. The list is fixed when the job starts, so checked URLs dropping
 * out of "due" don't shift the batches.
 */
class CheckUrlsJob extends BaseBatchedJob
{
    public int $batchSize = 20;

    /** @var array<array{urlHash: string, url: string, host: string}>|null */
    public ?array $items = null;

    protected function loadData(): Batchable
    {
        $this->items ??= LinkChecker::getInstance()->getUrls()->due();
        return new ListBatcher($this->items);
    }

    protected function processItem(mixed $item): void
    {
        LinkChecker::getInstance()->getUrls()->check([$item]);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('link-checker', 'Checking external links');
    }
}
