<?php

namespace statikbe\linkchecker\jobs;

use Craft;
use craft\base\Batchable;
use craft\queue\BaseBatchedJob;
use statikbe\linkchecker\helpers\ListBatcher;
use statikbe\linkchecker\LinkChecker;
use statikbe\linkchecker\services\Hosts;

/**
 * Looks up the domains of external links that are due (Hosts), in batches. A domain another job looked up in the meantime
 * is skipped.
 */
class CheckHostsJob extends BaseBatchedJob
{
    public int $batchSize = 50;

    /** @var string[]|null */
    public ?array $hosts = null;

    /** Whether DNS lookups work in this batch (Hosts::lookupsWork()) */
    private bool $lookupsWork = true;

    protected function loadData(): Batchable
    {
        $this->hosts ??= LinkChecker::getInstance()->getHosts()->due();
        return new ListBatcher($this->hosts);
    }

    protected function before(): void
    {
        LinkChecker::getInstance()->getHosts()->jobStarted();
    }

    protected function beforeBatch(): void
    {
        $this->lookupsWork = Hosts::lookupsWork();
        if (!$this->lookupsWork) {
            Craft::warning('Link Checker: DNS lookups fail (also for ' . Hosts::CANARY . '), so no domains were checked.', __METHOD__);
        }
    }

    protected function processItem(mixed $item): void
    {
        if ($this->lookupsWork) {
            LinkChecker::getInstance()->getHosts()->check([(string)$item], null, true);
        }
    }

    protected function after(): void
    {
        // The URLs of domains that exist can be checked now, and domains that became due while this job ran
        LinkChecker::getInstance()->getHosts()->queueIfDue();
        LinkChecker::getInstance()->getUrls()->queueIfDue();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('link-checker', 'Checking the domains of external links');
    }
}
