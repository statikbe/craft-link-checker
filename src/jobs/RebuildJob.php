<?php

namespace statikbe\linkchecker\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Entry;
use craft\queue\BaseBatchedJob;
use statikbe\linkchecker\LinkChecker;

/**
 * Reads the links of every page again, in batches (a page in every site at once).
 */
class RebuildJob extends BaseBatchedJob
{
    public int $batchSize = 50;

    protected function loadData(): Batchable
    {
        return new QueryBatcher(self::query());
    }

    protected function processItem(mixed $item): void
    {
        LinkChecker::getInstance()->getIndex()->index((int)$item['id']);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('link-checker', 'Finding the links in the content');
    }

    /**
     * The ids of the pages to read: entries in a section (the checked ones), canonical, in any site and status.
     */
    public static function query(): \craft\db\Query
    {
        $sections = LinkChecker::getInstance()->getSettings()->sections;
        $query = Entry::find()
            ->section($sections === '*' ? '*' : $sections)
            ->site('*')
            ->unique()
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->select(['elements.id'])
            ->orderBy(['elements.id' => SORT_ASC])
            ->asArray();
        return $query;
    }
}
