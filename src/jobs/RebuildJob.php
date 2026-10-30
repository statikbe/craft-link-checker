<?php

namespace statikbe\linkchecker\jobs;

use Craft;
use craft\base\Batchable;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\queue\BaseBatchedJob;
use statikbe\linkchecker\helpers\IdBatcher;
use statikbe\linkchecker\LinkChecker;

/**
 * Reads the links of every page again, in batches (a page in every site at once).
 */
class RebuildJob extends BaseBatchedJob
{
    public int $batchSize = 50;

    protected function loadData(): Batchable
    {
        return new IdBatcher(self::ids());
    }

    protected function processItem(mixed $item): void
    {
        LinkChecker::getInstance()->getIndex()->index((int)$item);
    }

    /**
     * The ids of the pages to read: the entries (query()) and the global sets.
     *
     * @return int[]
     */
    public static function ids(): array
    {
        $ids = array_map('intval', self::query()->column());
        if (LinkChecker::getInstance()->getSettings()->checkGlobals) {
            array_push($ids, ...array_map('intval', GlobalSet::find()->site('*')->unique()->ids()));
        }
        return $ids;
    }

    /**
     * After the last batch: the domains of the external links.
     */
    protected function after(): void
    {
        LinkChecker::getInstance()->getHosts()->queueIfDue();
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
