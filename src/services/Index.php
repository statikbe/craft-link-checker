<?php

namespace statikbe\linkchecker\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use statikbe\linkchecker\LinkChecker;

/**
 * The links table: the links of every page, per site. A page is read again when it (or a block on it) is saved, once per
 * request and for every site. A link's target isn't watched: whether it's broken is worked out when the report is read.
 */
class Index extends Component
{
    public const TABLE = '{{%linkchecker_links}}';
    public const ANCHORS_TABLE = '{{%linkchecker_anchors}}';

    /** @var array<int, true> pages to read again at the end of the request */
    private array $pending = [];

    /**
     * After an entry is saved: the page it's on is read again at the end of the request. Not for drafts, revisions, resaving
     * (`craft resave`, a project config change) or propagation to other sites: the page is read in every site anyway.
     */
    public function entrySaved(Entry $entry): void
    {
        if ($entry->getIsDraft() || $entry->getIsRevision() || $entry->resaving || $entry->propagating) {
            return;
        }
        $page = $this->page($entry);
        if ($page === null) {
            return;
        }
        if (!$this->pending) {
            Craft::$app->onAfterRequest(fn() => $this->indexPending());
        }
        $this->pending[(int)$page->id] = true;
    }

    /**
     * Removes the links of a page that was deleted (a hard delete removes them through the foreign key).
     */
    public function forget(Entry $entry): void
    {
        if ($entry->getSection() !== null && !$entry->getIsDraft() && !$entry->getIsRevision()) {
            Db::delete(self::TABLE, ['sourceId' => $entry->id]);
            Db::delete(self::ANCHORS_TABLE, ['pageId' => $entry->id]);
        }
    }

    /**
     * Reads the links of a page in every site it's in.
     */
    public function index(int $pageId): void
    {
        $plugin = LinkChecker::getInstance();
        $sites = Entry::find()->id($pageId)->site('*')->status(null)->all();
        $section = ($sites[0] ?? null)?->getSection();
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();
        try {
            Db::delete(self::TABLE, ['sourceId' => $pageId]);
            Db::delete(self::ANCHORS_TABLE, ['pageId' => $pageId]);
            if ($section !== null && $plugin->getSettings()->checksSection($section->handle)) {
                $now = Db::prepareDateForDb(new \DateTime());
                $rows = [];
                $anchors = [];
                foreach ($sites as $page) {
                    $result = $plugin->getExtractor()->extract($page);
                    // '' marks the page as read, also without anchors
                    foreach (['', ...$result['anchors']] as $anchor) {
                        $anchors[] = [$pageId, (int)$page->siteId, mb_substr($anchor, 0, 255)];
                    }
                    foreach ($result['links'] as $link) {
                        $rows[] = [
                            $link['sourceId'], $link['siteId'], $link['elementId'], $link['fieldId'], $link['location'],
                            $link['kind'], $link['targetId'], $link['targetSiteId'],
                            $link['uri'] !== null ? mb_substr($link['uri'], 0, 500) : null,
                            $link['url'], $link['suffix'] !== null ? mb_substr($link['suffix'], 0, 255) : null,
                            $link['label'], $link['issue'],
                            $link['fallbackUri'] !== null ? mb_substr($link['fallbackUri'], 0, 500) : null, $link['fallbackSiteId'],
                            $now, $now, StringHelper::UUID(),
                        ];
                    }
                }
                if ($anchors) {
                    Db::batchInsert(self::ANCHORS_TABLE, ['pageId', 'siteId', 'anchor'], $anchors);
                }
                if ($rows) {
                    Db::batchInsert(self::TABLE, [
                        'sourceId', 'siteId', 'elementId', 'fieldId', 'location', 'kind', 'targetId', 'targetSiteId', 'uri',
                        'url', 'suffix', 'label', 'issue', 'fallbackUri', 'fallbackSiteId', 'dateCreated', 'dateUpdated', 'uid',
                    ], $rows);
                }
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Whether the table has been filled (a rebuild ran).
     */
    public function isEmpty(): bool
    {
        return !(new Query())->from(self::TABLE)->exists();
    }

    private function indexPending(): void
    {
        $pending = array_keys($this->pending);
        $this->pending = [];
        foreach ($pending as $id) {
            try {
                $this->index($id);
            } catch (\Throwable $e) {
                Craft::error("Couldn't read the links of entry {$id}: {$e->getMessage()}", __METHOD__);
            }
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
