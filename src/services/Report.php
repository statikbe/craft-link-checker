<?php

namespace statikbe\linkchecker\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use statikbe\linkchecker\helpers\LinkParser;
use statikbe\linkchecker\LinkChecker;

/**
 * Which links are broken, worked out from the targets' current state each time the report is read (deleted, in the trash,
 * disabled, expired, without a URL…), so it's always up to date without watching the targets.
 *
 * A problem has a severity:
 * - broken: the link leads nowhere (counted in the widget's headline);
 * - warning: the link works but may not be what the editor wants (an entry that isn't live yet, a link to another
 *   language, an invalid email address or phone number).
 */
class Report extends Component
{
    public const BROKEN = 'broken';
    public const WARNING = 'warning';

    /** Reasons per severity */
    public const REASONS = [
        'deleted' => self::BROKEN,
        'trashed' => self::BROKEN,
        'disabled' => self::BROKEN,
        'not-in-site' => self::BROKEN,
        'expired' => self::BROKEN,
        'no-url' => self::BROKEN,
        'page-not-found' => self::BROKEN,
        'redirected' => self::WARNING,
        'anchor-missing' => self::WARNING,
        'pending' => self::WARNING,
        'deleted-fallback' => self::WARNING,
        'other-site' => self::WARNING,
        'invalid-email' => self::WARNING,
        'invalid-phone' => self::WARNING,
    ];

    /**
     * The pages with problems, grouped per page and site, broken ones first, then by title.
     *
     * @param bool $warnings also the pages that only have warnings
     * @param User|null $user only pages in sections this user may edit (null: every page)
     * @return array<array{sourceId: int, siteId: int, title: string, broken: int, warnings: int, links: array<array<string, mixed>>}>
     */
    public function pages(bool $warnings = false, ?User $user = null): array
    {
        $pages = [];
        foreach ($this->problems($user) as $link) {
            if ($link['severity'] === self::WARNING && !$warnings) {
                continue;
            }
            $key = $link['sourceId'] . '-' . $link['siteId'];
            $pages[$key] ??= [
                'sourceId' => (int)$link['sourceId'],
                'siteId' => (int)$link['siteId'],
                'title' => (string)$link['sourceTitle'],
                'broken' => 0,
                'warnings' => 0,
                'links' => [],
            ];
            $pages[$key][$link['severity'] === self::BROKEN ? 'broken' : 'warnings']++;
            $pages[$key]['links'][] = $link;
        }
        $pages = array_values($pages);
        usort($pages, fn(array $a, array $b) => [$b['broken'] > 0, $a['title'], $a['siteId']] <=> [$a['broken'] > 0, $b['title'], $b['siteId']]);
        return $pages;
    }

    /**
     * The links of one page in one site that have a problem.
     *
     * @return array<array<string, mixed>>
     */
    public function pageProblems(int $sourceId, int $siteId): array
    {
        return array_values(array_filter(
            $this->problems(null, $sourceId),
            fn(array $link) => (int)$link['siteId'] === $siteId,
        ));
    }

    /**
     * Every link with a problem, with its reason and severity.
     *
     * @return array<array<string, mixed>>
     */
    public function problems(?User $user = null, ?int $sourceId = null): array
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $elementKinds = LinkParser::ELEMENT_KINDS;
        $settings = LinkChecker::getInstance()->getSettings();
        $crossSite = $settings->crossSiteLinks === 'warning';

        $query = (new Query())
            ->select([
                'l.*',
                'sourceTitle' => 'ses.title',
                'targetElementId' => 'te.id', 'targetDeleted' => 'te.dateDeleted', 'targetEnabled' => 'te.enabled',
                'targetArchived' => 'te.archived', 'targetSiteRow' => 'tes.id', 'targetSiteEnabled' => 'tes.enabled',
                'targetUri' => 'tes.uri', 'targetTitle' => 'tes.title', 'targetPostDate' => 'ten.postDate',
                'targetExpiryDate' => 'ten.expiryDate',
            ])
            ->from(['l' => Index::TABLE])
            // The page: not in the trash, a canonical entry
            ->innerJoin(['se' => Table::ELEMENTS], '[[se.id]] = [[l.sourceId]]')
            ->innerJoin(['ses' => Table::ELEMENTS_SITES], '[[ses.elementId]] = [[l.sourceId]] AND [[ses.siteId]] = [[l.siteId]]')
            ->innerJoin(['sen' => Table::ENTRIES], '[[sen.id]] = [[l.sourceId]]')
            // The target of an element link
            ->leftJoin(['te' => Table::ELEMENTS], '[[te.id]] = [[l.targetId]]')
            ->leftJoin(['tes' => Table::ELEMENTS_SITES], '[[tes.elementId]] = [[l.targetId]] AND [[tes.siteId]] = [[l.targetSiteId]]')
            ->leftJoin(['ten' => Table::ENTRIES], '[[ten.id]] = [[l.targetId]]')
            ->where(['se.dateDeleted' => null, 'se.draftId' => null, 'se.revisionId' => null])
            ->andWhere(['or',
                ['not', ['l.issue' => null]],
                $settings->checkAnchors ? ['not', ['l.suffix' => null]] : '1 = 0',
                ['l.kind' => LinkParser::KIND_URL],
                ['and',
                    ['l.kind' => $elementKinds],
                    ['or',
                        ['te.id' => null],
                        ['not', ['te.dateDeleted' => null]],
                        ['te.enabled' => false],
                        ['te.archived' => true],
                        ['tes.id' => null],
                        ['tes.enabled' => false],
                        ['and', ['l.kind' => [LinkParser::KIND_ENTRY, LinkParser::KIND_CATEGORY]], ['tes.uri' => null]],
                        ['<=', 'ten.expiryDate', $now],
                        ['>', 'ten.postDate', $now],
                        $crossSite ? '[[l.targetSiteId]] <> [[l.siteId]]' : '1 = 0',
                    ],
                ],
            ])
            ->orderBy(['l.sourceId' => SORT_ASC, 'l.siteId' => SORT_ASC, 'l.id' => SORT_ASC]);

        if ($sourceId !== null) {
            $query->andWhere(['l.sourceId' => $sourceId]);
        }
        if ($user !== null && !$user->admin) {
            $sections = $this->editableSectionIds($user);
            if (!$sections) {
                return [];
            }
            $query->andWhere(['sen.sectionId' => $sections]);
        }

        $rows = $query->all();
        $live = $this->liveUris(array_merge(
            array_filter($rows, fn(array $row) => $row['kind'] === LinkParser::KIND_URL),
            array_map(
                fn(array $row) => ['targetSiteId' => $row['fallbackSiteId'], 'uri' => $row['fallbackUri']],
                array_filter($rows, fn(array $row) => $row['fallbackUri'] !== null),
            ),
        ));

        // First the problems of the links themselves, then the anchors of the links that work
        $problems = [];
        $anchorChecks = [];
        foreach ($rows as $i => $row) {
            $reason = $this->reason($row, $live, $now, $crossSite);
            if ($reason !== null) {
                $problems[$i] = $reason;
            } elseif ($settings->checkAnchors && ($target = $this->anchorTarget($row, $live)) !== null) {
                $anchorChecks[$i] = $target;
            }
        }
        if ($anchorChecks) {
            $anchors = $this->anchors(array_values($anchorChecks));
            foreach ($anchorChecks as $i => [$pageId, $siteId, $anchor]) {
                $defined = $anchors[$pageId . ':' . $siteId] ?? null;
                // Only for a page that was read (its anchors are known), and an anchor the templates don't render
                if ($defined !== null && !isset($defined[$anchor]) && !$settings->isKnownAnchor($anchor)) {
                    $problems[$i] = 'anchor-missing';
                }
            }
        }

        $result = [];
        foreach ($rows as $i => $row) {
            if (!isset($problems[$i])) {
                continue;
            }
            $row['reason'] = $problems[$i];
            $row['severity'] = self::REASONS[$problems[$i]];
            $result[] = $row;
        }
        return $result;
    }

    /**
     * Counts for a summary: pages with broken links and broken links (and the same for warnings).
     *
     * @return array{pages: int, broken: int, warningPages: int, warnings: int}
     */
    public function counts(?User $user = null): array
    {
        $counts = ['pages' => 0, 'broken' => 0, 'warningPages' => 0, 'warnings' => 0];
        foreach ($this->pages(true, $user) as $page) {
            $counts['broken'] += $page['broken'];
            $counts['warnings'] += $page['warnings'];
            $counts[$page['broken'] ? 'pages' : 'warningPages']++;
        }
        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $live
     */
    private function reason(array $row, array $live, string $now, bool $crossSite): ?string
    {
        if (in_array($row['kind'], LinkParser::ELEMENT_KINDS, true)) {
            // The element is gone, but Craft renders the saved fallback URL, which still leads to a live page
            $gone = $row['targetElementId'] === null || $row['targetDeleted'] !== null;
            if ($gone && $row['fallbackUri'] !== null && isset($live[$row['fallbackSiteId'] . ':' . strtolower((string)$row['fallbackUri'])])) {
                return 'deleted-fallback';
            }
            return match (true) {
                $row['targetElementId'] === null => 'deleted',
                $row['targetDeleted'] !== null => 'trashed',
                $row['targetSiteRow'] === null => 'not-in-site',
                !$row['targetEnabled'] || !$row['targetSiteEnabled'] || (bool)$row['targetArchived'] => 'disabled',
                $row['kind'] !== LinkParser::KIND_ASSET && $row['targetUri'] === null => 'no-url',
                $row['targetExpiryDate'] !== null && $row['targetExpiryDate'] <= $now => 'expired',
                $row['targetPostDate'] !== null && $row['targetPostDate'] > $now => 'pending',
                $crossSite && (int)$row['targetSiteId'] !== (int)$row['siteId'] => 'other-site',
                default => null,
            };
        }
        if ($row['kind'] === LinkParser::KIND_URL) {
            if ($row['issue'] === 'route') {
                return null;
            }
            if (isset($live[$row['targetSiteId'] . ':' . strtolower((string)$row['uri'])])) {
                return null;
            }
            return $this->redirected($row) ? 'redirected' : 'page-not-found';
        }
        return isset(self::REASONS[$row['issue'] ?? '']) ? $row['issue'] : null;
    }

    /**
     * The page, site and anchor a working link with an anchor (#…) points to, or null when it has none: an entry link with a
     * suffix, an own-site URL with a fragment, or #… on the same page.
     *
     * @param array<string, mixed> $row
     * @param array<string, int> $live
     * @return array{0: int, 1: int, 2: string}|null
     */
    private function anchorTarget(array $row, array $live): ?array
    {
        $anchor = LinkParser::fragment($row['suffix'] ?? null);
        if ($anchor === null) {
            return null;
        }
        return match ($row['kind']) {
            LinkParser::KIND_ENTRY, LinkParser::KIND_CATEGORY => [(int)$row['targetId'], (int)$row['targetSiteId'], $anchor],
            LinkParser::KIND_URL => isset($live[$row['targetSiteId'] . ':' . strtolower((string)$row['uri'])])
                ? [$live[$row['targetSiteId'] . ':' . strtolower((string)$row['uri'])], (int)$row['targetSiteId'], $anchor]
                : null,
            LinkParser::KIND_ANCHOR => [(int)$row['sourceId'], (int)$row['siteId'], $anchor],
            default => null,
        };
    }

    /**
     * The anchors of these pages, for the pages that were read: 'pageId:siteId' => [anchor => true].
     *
     * @param array<array{0: int, 1: int, 2: string}> $targets
     * @return array<string, array<string, true>>
     */
    private function anchors(array $targets): array
    {
        $anchors = [];
        foreach (array_chunk(array_values(array_unique(array_column($targets, 0))), 500) as $pageIds) {
            $rows = (new Query())
                ->select(['pageId', 'siteId', 'anchor'])
                ->from(Index::ANCHORS_TABLE)
                ->where(['pageId' => $pageIds])
                ->all();
            foreach ($rows as $row) {
                $key = $row['pageId'] . ':' . $row['siteId'];
                $anchors[$key] ??= [];
                if ($row['anchor'] !== '') {
                    $anchors[$key][(string)$row['anchor']] = true;
                }
            }
        }
        return $anchors;
    }

    /**
     * Whether a redirect plugin sends this URL on (the URL as stored, a root-relative path made absolute).
     *
     * @param array<string, mixed> $row
     */
    private function redirected(array $row): bool
    {
        $url = (string)$row['url'];
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $url = 'https://localhost' . $url;
        }
        return LinkChecker::getInstance()->getRedirects()->matches($url, (int)$row['targetSiteId']);
    }

    /**
     * Which of the URIs of these links belong to a live element in their site: enabled, not in the trash, canonical, and for
     * an entry already published and not expired.
     *
     * @param array<array<string, mixed>> $rows
     * @return array<string, int> 'siteId:uri' => element id
     */
    private function liveUris(array $rows): array
    {
        $live = [];
        $bySite = [];
        foreach ($rows as $row) {
            $bySite[(int)$row['targetSiteId']][] = (string)$row['uri'];
        }
        $now = Db::prepareDateForDb(new \DateTime());
        foreach ($bySite as $siteId => $uris) {
            foreach (array_chunk(array_values(array_unique($uris)), 500) as $chunk) {
                $found = (new Query())
                    ->select(['es.uri', 'es.elementId'])
                    ->from(['es' => Table::ELEMENTS_SITES])
                    ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[es.elementId]]')
                    ->leftJoin(['en' => Table::ENTRIES], '[[en.id]] = [[es.elementId]]')
                    ->where([
                        'es.siteId' => $siteId,
                        'es.uri' => $chunk,
                        'es.enabled' => true,
                        'e.enabled' => true,
                        'e.archived' => false,
                        'e.dateDeleted' => null,
                        'e.draftId' => null,
                        'e.revisionId' => null,
                    ])
                    ->andWhere(['or', ['en.postDate' => null], ['<=', 'en.postDate', $now]])
                    ->andWhere(['or', ['en.expiryDate' => null], ['>', 'en.expiryDate', $now]])
                    ->all();
                foreach ($found as $item) {
                    $live[$siteId . ':' . strtolower((string)$item['uri'])] = (int)$item['elementId'];
                }
            }
        }
        return $live;
    }

    /**
     * The sections whose entries the user may edit.
     *
     * @return int[]
     */
    private function editableSectionIds(User $user): array
    {
        $ids = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($user->can("saveEntries:$section->uid") || $user->can("savePeerEntries:$section->uid")) {
                $ids[] = (int)$section->id;
            }
        }
        return $ids;
    }

    /**
     * A page's edit URL in the control panel, in the page's site.
     */
    public function editUrl(int $sourceId, int $siteId): ?string
    {
        $entry = Entry::find()->id($sourceId)->siteId($siteId)->status(null)->one();
        return $entry?->getCpEditUrl();
    }
}
