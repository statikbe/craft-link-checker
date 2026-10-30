<?php

namespace statikbe\linkchecker\jobs;

use Craft;
use craft\base\Batchable;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\queue\BaseBatchedJob;
use statikbe\linkchecker\helpers\CursorBatcher;
use statikbe\linkchecker\LinkChecker;

/**
 * Reads the links of every page again, in batches (a page in every site at once). Each batch takes the next pages after the
 * last one it read, so the job carries no list.
 */
class RebuildJob extends BaseBatchedJob
{
    public int $batchSize = 25;

    /** @var int the id of the last page read */
    public int $lastId = 0;

    /** @var int|null the pages when the job started */
    public ?int $total = null;

    protected function loadData(): Batchable
    {
        $this->total ??= self::count();
        return new CursorBatcher($this->total, fn(int $limit) => self::nextIds($this->lastId, $limit));
    }

    protected function processItem(mixed $item): void
    {
        // One page that can't be read doesn't stop the rebuild
        try {
            LinkChecker::getInstance()->getIndex()->index((int)$item);
        } catch (\Throwable $e) {
            Craft::error("Couldn't read the links of element {$item}: {$e->getMessage()}", __METHOD__);
        }
        $this->lastId = (int)$item;
    }

    protected function afterBatch(): void
    {
        // The new domains and URLs of this batch get their rows
        LinkChecker::getInstance()->getIndex()->flushExternal();
    }

    /**
     * The ids of the pages to read: the entries (query()) and the global sets.
     *
     * @return int[]
     */
    public static function ids(): array
    {
        return self::nextIds(0, null);
    }

    /**
     * The ids of the next pages to read after a page id, in order.
     *
     * @return int[]
     */
    public static function nextIds(int $afterId, ?int $limit): array
    {
        $ids = array_map('intval', self::query()->andWhere(['>', 'elements.id', $afterId])->limit($limit)->column());
        if (LinkChecker::getInstance()->getSettings()->checkGlobals) {
            array_push($ids, ...array_map('intval', self::globalSetQuery()->andWhere(['>', 'elements.id', $afterId])->limit($limit)->column()));
            sort($ids);
            if ($limit !== null) {
                $ids = array_slice($ids, 0, $limit);
            }
        }
        return $ids;
    }

    /**
     * The number of pages to read.
     */
    public static function count(): int
    {
        $count = (int)self::query()->count();
        if (LinkChecker::getInstance()->getSettings()->checkGlobals) {
            $count += (int)self::globalSetQuery()->count();
        }
        return $count;
    }

    /**
     * After the last batch: the domains of the external links.
     */
    protected function after(): void
    {
        LinkChecker::getInstance()->getIndex()->indexed();
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

    /**
     * The ids of the global sets, in order.
     */
    private static function globalSetQuery(): \craft\db\Query
    {
        return GlobalSet::find()->site('*')->unique()->select(['elements.id'])->orderBy(['elements.id' => SORT_ASC])->asArray();
    }
}
