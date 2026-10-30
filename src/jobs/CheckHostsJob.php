<?php

namespace statikbe\linkchecker\jobs;

use Craft;
use craft\base\Batchable;
use craft\queue\BaseBatchedJob;
use statikbe\linkchecker\helpers\CursorBatcher;
use statikbe\linkchecker\LinkChecker;
use statikbe\linkchecker\services\Hosts;

/**
 * Looks up the domains of external links that are due (Hosts), in batches. Each batch takes the next due domains after the
 * last one it looked up, so the job carries no list.
 */
class CheckHostsJob extends BaseBatchedJob
{
    public int $batchSize = 25;

    /** @var int the id of the last domain looked up */
    public int $lastId = 0;

    /** @var int|null the domains that were due when the job started */
    public ?int $total = null;

    /** Whether DNS lookups work in this batch (Hosts::lookupsWork()) */
    private bool $lookupsWork = true;

    protected function loadData(): Batchable
    {
        $hosts = LinkChecker::getInstance()->getHosts();
        $this->total ??= (int)$hosts->dueQuery()->count();
        return new CursorBatcher($this->total, fn(int $limit) => $hosts->due($this->lastId, $limit));
    }

    protected function beforeBatch(): void
    {
        LinkChecker::getInstance()->getHosts()->keepQueued();
        $this->lookupsWork = Hosts::lookupsWork();
        if (!$this->lookupsWork) {
            Craft::warning('Link Checker: DNS lookups fail (also for ' . Hosts::CANARY . '), so no domains were checked.', __METHOD__);
        }
    }

    protected function processItem(mixed $item): void
    {
        if ($this->lookupsWork) {
            LinkChecker::getInstance()->getHosts()->checkOne((string)$item['host']);
        }
        $this->lastId = (int)$item['id'];
    }

    protected function after(): void
    {
        // The URLs of domains that exist can be checked now, and domains that became due while this job ran
        LinkChecker::getInstance()->getHosts()->jobFinished();
        LinkChecker::getInstance()->getUrls()->queueIfDue();
    }

    public function __wakeup(): void
    {
        $this->lookupsWork = true;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('link-checker', 'Checking the domains of external links');
    }
}
