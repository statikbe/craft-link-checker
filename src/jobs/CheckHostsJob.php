<?php

namespace statikbe\linkchecker\jobs;

use Craft;
use craft\queue\BaseJob;
use statikbe\linkchecker\LinkChecker;

/**
 * Looks up the domains of external links that are due (Hosts).
 */
class CheckHostsJob extends BaseJob
{
    public function execute($queue): void
    {
        $hosts = LinkChecker::getInstance()->getHosts();
        $hosts->check($hosts->due(), fn(int $done, int $total) => $this->setProgress($queue, $done / max(1, $total)));
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('link-checker', 'Checking the domains of external links');
    }
}
