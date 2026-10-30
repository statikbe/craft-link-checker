<?php

namespace statikbe\linkchecker\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use statikbe\linkchecker\helpers\LinkParser;
use statikbe\linkchecker\jobs\IndexPagesJob;
use statikbe\linkchecker\LinkChecker;

/**
 * The links table: the links of every page, per site. A page is read again when it (or a block on it) is saved: the pages
 * saved in a request (or a queue job) are added to the pending table at its end, and one queue job at a time reads them, in
 * batches. A link's target isn't watched: whether it's broken is worked out when the report is read.
 */
class Index extends Component
{
    public const TABLE = '{{%linkchecker_links}}';
    public const ANCHORS_TABLE = '{{%linkchecker_anchors}}';
    public const PENDING_TABLE = '{{%linkchecker_pending}}';

    /** Set while an IndexPagesJob is waiting or running, so saves don't push another one */
    private const QUEUED_KEY = 'linkchecker:pages-queued';

    /** @var array<int, true> pages to read again after the request or queue job */
    private array $pending = [];

    /** Whether flushPending() runs at the end of the request (once per process) */
    private bool $flushRegistered = false;

    /** @var array<string, array{urlHash: string, url: string, host: string}> external URLs of the pages read, not added yet */
    private array $external = [];

    /** Whether flushExternal() runs at the end of the request (once per process) */
    private bool $externalRegistered = false;

    /**
     * After an entry or global set is saved: the page it's on is read again in a queue job. Not for drafts, revisions,
     * resaving (`craft resave`, a project config change) or propagation to other sites: the page is read in every site
     * anyway.
     */
    public function elementSaved(ElementInterface $element): void
    {
        if ($element->getIsDraft() || $element->getIsRevision() || $element->resaving || $element->propagating) {
            return;
        }
        $page = $element instanceof Entry ? $this->page($element) : ($element instanceof GlobalSet ? $element : null);
        if ($page === null) {
            return;
        }
        if (!$this->flushRegistered) {
            // At the end of the request; after a queue job (an import) too, which ends long before its worker does
            $this->flushRegistered = true;
            Craft::$app->onAfterRequest(fn() => $this->flushPending());
        }
        $this->pending[(int)$page->id] = true;
    }

    /**
     * Adds the pages saved so far to the pending table (LinkChecker also calls this after every queue job).
     */
    public function flushPending(): void
    {
        if (!$this->pending) {
            return;
        }
        $ids = array_keys($this->pending);
        $this->pending = [];
        try {
            $this->queuePages($ids);
        } catch (\Throwable $e) {
            // Never fail a save that's done
            Craft::error("Couldn't queue reading the links of elements " . implode(', ', $ids) . ": {$e->getMessage()}", __METHOD__);
        }
    }

    /**
     * Marks pages to be read again and pushes the job that reads them, unless one is waiting or running already (it reads
     * the pages added while it runs too).
     *
     * @param int[] $ids
     */
    public function queuePages(array $ids): void
    {
        $queuedAt = (int)(microtime(true) * 1_000_000);
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $chunk) {
            $existing = array_map('intval', (new Query())->select(['pageId'])->from(self::PENDING_TABLE)->where(['pageId' => $chunk])->column());
            if ($existing) {
                Db::update(self::PENDING_TABLE, ['queuedAt' => $queuedAt], ['pageId' => $existing], [], false);
            }
            $new = array_diff($chunk, $existing);
            if ($new) {
                try {
                    Db::batchInsert(self::PENDING_TABLE, ['pageId', 'queuedAt'], array_map(fn(int $id) => [$id, $queuedAt], $new));
                } catch (\Throwable) {
                    // Another request added one of them meanwhile
                    foreach ($new as $id) {
                        Db::upsert(self::PENDING_TABLE, ['pageId' => $id, 'queuedAt' => $queuedAt]);
                    }
                }
            }
        }
        $this->queueIfPending();
    }

    /**
     * Pushes the job that reads the pending pages, unless one is waiting or running.
     */
    public function queueIfPending(): void
    {
        $cache = Craft::$app->getCache();
        if ($cache->exists(self::QUEUED_KEY) || !(new Query())->from(self::PENDING_TABLE)->exists()) {
            return;
        }
        if ($cache->add(self::QUEUED_KEY, true, 3600)) {
            LinkChecker::push(new IndexPagesJob());
        }
    }

    /**
     * A batch of the job started: it's still running (the key expires an hour after the last batch, when a job died).
     */
    public function keepQueued(): void
    {
        Craft::$app->getCache()->set(self::QUEUED_KEY, true, 3600);
    }

    /**
     * The job is done: pages saved since its last batch get a new one.
     */
    public function jobFinished(): void
    {
        Craft::$app->getCache()->delete(self::QUEUED_KEY);
        $this->queueIfPending();
    }

    /**
     * The next pending pages after a pending row id: [['id', 'pageId', 'queuedAt'], …].
     *
     * @return array<array{id: int|string, pageId: int|string, queuedAt: int|string}>
     */
    public function pendingPages(int $afterId, int $limit): array
    {
        return (new Query())
            ->select(['id', 'pageId', 'queuedAt'])
            ->from(self::PENDING_TABLE)
            ->where(['>', 'id', $afterId])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->all();
    }

    /**
     * Reads a pending page and removes it from the pending table, unless it was saved again meanwhile (then it's read
     * again).
     *
     * @param array{pageId: int|string, queuedAt: int|string} $row
     */
    public function indexPending(array $row): void
    {
        try {
            $this->index((int)$row['pageId']);
        } catch (\Throwable $e) {
            Craft::error("Couldn't read the links of element {$row['pageId']}: {$e->getMessage()}", __METHOD__);
        }
        Db::delete(self::PENDING_TABLE, ['and', ['pageId' => (int)$row['pageId']], ['<=', 'queuedAt', (int)$row['queuedAt']]]);
    }

    /**
     * Removes the links of a page that was deleted (a hard delete removes them through the foreign key).
     */
    public function forget(ElementInterface $entry): void
    {
        $page = $entry instanceof GlobalSet || ($entry instanceof Entry && $entry->getSection() !== null);
        if ($page && !$entry->getIsDraft() && !$entry->getIsRevision()) {
            Db::delete(self::TABLE, ['sourceId' => $entry->id]);
            Db::delete(self::ANCHORS_TABLE, ['pageId' => $entry->id]);
            Report::markDirty();
        }
    }

    /**
     * Reads the links of a page (an entry in a section, or a global set) in every site it's in. The nested entries are
     * loaded a level at a time for all their siblings (Extractor); the page's copies in the other sites aren't siblings,
     * since eager loading would mix up their sites.
     */
    public function index(int $pageId): void
    {
        $plugin = LinkChecker::getInstance();
        $settings = $plugin->getSettings();
        $type = (new Query())->select(['type'])->from(\craft\db\Table::ELEMENTS)->where(['id' => $pageId])->scalar();
        if ($type === GlobalSet::class) {
            $sites = $settings->checkGlobals ? GlobalSet::find()->id($pageId)->site('*')->all() : [];
            $checked = $settings->checkGlobals;
        } else {
            $sites = Entry::find()->id($pageId)->site('*')->status(null)->all();
            $checked = null;
        }

        // Read every site first: the transaction only covers the writes
        $now = Db::prepareDateForDb(new \DateTime());
        $rows = [];
        $anchors = [];
        $external = [];
        foreach ($sites as $page) {
            $page->elementQueryResult = null;
            if ($checked === null) {
                $section = $page instanceof Entry ? $page->getSection() : null;
                $checked = $section !== null && $settings->checksSection($section->handle);
            }
            if (!$checked) {
                break;
            }
            $result = $plugin->getExtractor()->extract($page);
            // '' marks the page as read, also without anchors
            foreach (['', ...$result['anchors']] as $anchor) {
                $anchors[] = [$pageId, (int)$page->siteId, mb_substr($anchor, 0, 255)];
            }
            foreach ($result['links'] as $link) {
                $host = $link['host'] !== null ? mb_substr($link['host'], 0, 255) : null;
                $hash = $link['kind'] === LinkParser::KIND_EXTERNAL && $host !== null ? sha1(LinkParser::normalizeUrl($link['url'])) : null;
                if ($hash !== null) {
                    $external[$hash] ??= ['urlHash' => $hash, 'url' => $link['url'], 'host' => $host];
                }
                $rows[] = [
                    $link['sourceId'], $link['siteId'], $link['elementId'], $link['fieldId'], $link['location'],
                    $link['kind'], $link['targetId'], $link['targetSiteId'],
                    $link['uri'] !== null ? mb_substr($link['uri'], 0, 500) : null,
                    $link['url'], $link['suffix'] !== null ? mb_substr($link['suffix'], 0, 255) : null,
                    $link['label'], $link['issue'],
                    $link['fallbackUri'] !== null ? mb_substr($link['fallbackUri'], 0, 500) : null, $link['fallbackSiteId'],
                    $host, $hash, $now, $now, StringHelper::UUID(),
                ];
            }
        }
        if (!$checked) {
            $rows = $anchors = $external = [];
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();
        try {
            Db::delete(self::TABLE, ['sourceId' => $pageId]);
            Db::delete(self::ANCHORS_TABLE, ['pageId' => $pageId]);
            if ($anchors) {
                Db::batchInsert(self::ANCHORS_TABLE, ['pageId', 'siteId', 'anchor'], $anchors);
            }
            if ($rows) {
                Db::batchInsert(self::TABLE, [
                    'sourceId', 'siteId', 'elementId', 'fieldId', 'location', 'kind', 'targetId', 'targetSiteId', 'uri',
                    'url', 'suffix', 'label', 'issue', 'fallbackUri', 'fallbackSiteId', 'host', 'urlHash', 'dateCreated', 'dateUpdated', 'uid',
                ], $rows);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        // New domains and URLs get a row, due at once (the queue jobs take the due rows): once for a batch of pages
        if ($external) {
            $this->external += $external;
            if (!$this->externalRegistered) {
                $this->externalRegistered = true;
                Craft::$app->onAfterRequest(fn() => $this->flushExternal());
            }
            if (count($this->external) >= 1000) {
                $this->flushExternal();
            }
        }
    }

    /**
     * Adds the rows of the new domains and URLs of the pages read so far (after a batch of pages, and at the end of the
     * request).
     */
    public function flushExternal(): void
    {
        if (!$this->external) {
            return;
        }
        $external = array_values($this->external);
        $this->external = [];
        $plugin = LinkChecker::getInstance();
        $plugin->getHosts()->addHosts(array_values(array_unique(array_column($external, 'host'))));
        $plugin->getUrls()->addUrls($external);
    }

    /**
     * Whether the table has been filled (a rebuild ran).
     */
    public function isEmpty(): bool
    {
        return !(new Query())->from(self::TABLE)->exists();
    }

    /**
     * After pages were read (a batch of a job, a rebuild): the report is read again, and new domains and external URLs are
     * checked in queue jobs.
     */
    public function indexed(): void
    {
        $this->flushExternal();
        Report::markDirty();
        $plugin = LinkChecker::getInstance();
        try {
            $plugin->getHosts()->queueIfDue();
            $plugin->getUrls()->queueIfDue();
        } catch (\Throwable $e) {
            Craft::error("Couldn't queue the external link checks: {$e->getMessage()}", __METHOD__);
        }
    }

    /**
     * The page a saved entry is on: the entry itself when it's in a section, or the owner a nested entry belongs to.
     */
    private function page(Entry $entry): ?Entry
    {
        $page = $entry;
        for ($i = 0; $page->getSection() === null && $i < 8; $i++) {
            $owner = $page->getPrimaryOwner();
            if (!$owner instanceof Entry || $owner->getIsDraft() || $owner->getIsRevision()) {
                return null;
            }
            $page = $owner;
        }
        return $page->getSection() !== null ? $page : null;
    }
}
