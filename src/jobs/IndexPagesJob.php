<?php

namespace statikbe\linkchecker\jobs;

use Craft;
use craft\queue\BaseJob;
use statikbe\linkchecker\LinkChecker;

/**
 * Reads the links of pages that were saved, after the editor's request, then queues the checks of new domains and external
 * links.
 */
class IndexPagesJob extends BaseJob
{
    /** @var int[] */
    public array $ids = [];

    public function execute($queue): void
    {
        $index = LinkChecker::getInstance()->getIndex();
        foreach (array_values($this->ids) as $i => $id) {
            $this->setProgress($queue, $i / max(1, count($this->ids)));
            try {
                $index->index((int)$id);
            } catch (\Throwable $e) {
                Craft::error("Couldn't read the links of element {$id}: {$e->getMessage()}", __METHOD__);
            }
        }
        $index->indexed();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('link-checker', 'Finding the links in the content');
    }
}
