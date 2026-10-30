<?php

namespace statikbe\linkchecker\jobs;

use Craft;
use craft\base\Batchable;
use craft\queue\BaseBatchedJob;
use statikbe\linkchecker\helpers\ListBatcher;
use statikbe\linkchecker\LinkChecker;

/**
 * Checks the external URLs that are due (Urls), in batches. The list is fixed when the job is pushed, so checked URLs
 * dropping out of "due" don't shift the batches; a URL another job checked in the meantime is skipped.
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

    protected function before(): void
    {
        // Saves from now on may push a new job, for the URLs this one doesn't have
        LinkChecker::getInstance()->getUrls()->jobStarted();
    }

    protected function processItem(mixed $item): void
    {
        LinkChecker::getInstance()->getUrls()->check([$item]);
    }

    protected function after(): void
    {
        // URLs that became due while this job ran
        LinkChecker::getInstance()->getUrls()->queueIfDue();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('link-checker', 'Checking external links');
    }
}
