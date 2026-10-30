<?php

namespace statikbe\linkchecker\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\helpers\Db;
use statikbe\linkchecker\helpers\LinkParser;
use statikbe\linkchecker\LinkChecker;
use statikbe\linkchecker\models\Settings;

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
        'domain-not-found' => self::BROKEN,
        'external-not-found' => self::BROKEN,
        'moved-permanently' => self::WARNING,
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
                'sourceTitle' => 'coalesce([[ses.title]], [[gs.name]])',
                'targetElementId' => 'te.id', 'targetDeleted' => 'te.dateDeleted', 'targetEnabled' => 'te.enabled',
                'targetArchived' => 'te.archived', 'targetSiteRow' => 'tes.id', 'targetSiteEnabled' => 'tes.enabled',
                'targetUri' => 'tes.uri', 'targetTitle' => 'tes.title', 'targetPostDate' => 'ten.postDate',
                'targetExpiryDate' => 'ten.expiryDate',
                'hostResolves' => 'h.resolves',
                'externalStatus' => 'u.status',
                'externalCode' => 'u.httpCode',
                'externalFinalUrl' => 'u.finalUrl',
            ])
            ->from(['l' => Index::TABLE])
            // The page: not in the trash, a canonical entry
            ->innerJoin(['se' => Table::ELEMENTS], '[[se.id]] = [[l.sourceId]]')
            ->innerJoin(['ses' => Table::ELEMENTS_SITES], '[[ses.elementId]] = [[l.sourceId]] AND [[ses.siteId]] = [[l.siteId]]')
            // An entry, or a global set
            ->leftJoin(['sen' => Table::ENTRIES], '[[sen.id]] = [[l.sourceId]]')
            ->leftJoin(['gs' => Table::GLOBALSETS], '[[gs.id]] = [[l.sourceId]]')
            // The target of an element link
            ->leftJoin(['te' => Table::ELEMENTS], '[[te.id]] = [[l.targetId]]')
            ->leftJoin(['tes' => Table::ELEMENTS_SITES], '[[tes.elementId]] = [[l.targetId]] AND [[tes.siteId]] = [[l.targetSiteId]]')
            ->leftJoin(['ten' => Table::ENTRIES], '[[ten.id]] = [[l.targetId]]')
            // The domain of an external link
            ->leftJoin(['h' => Hosts::TABLE], '[[h.host]] = [[l.host]]')
            // The last check of an external page
            ->leftJoin(['u' => Urls::TABLE], '[[u.urlHash]] = [[l.urlHash]]')
            ->where(['se.dateDeleted' => null, 'se.draftId' => null, 'se.revisionId' => null])
            ->andWhere(['or', ['not', ['sen.id' => null]], ['not', ['gs.id' => null]]])
            ->andWhere(['or',
                ['not', ['l.issue' => null]],
                $settings->checkAnchors ? ['not', ['l.suffix' => null]] : '1 = 0',
                $settings->checkDomains ? ['h.resolves' => false] : '1 = 0',
                $settings->checkExternal ? ['u.status' => [Urls::BROKEN, Urls::REDIRECT]] : '1 = 0',
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
            $globalSets = $this->editableGlobalSetIds($user);
            if (!$sections && !$globalSets) {
                return [];
            }
            $query->andWhere(['or', ['sen.sectionId' => $sections ?: [0]], ['gs.id' => $globalSets ?: [0]]]);
        }

        return $this->judge($query->all());
    }

    /**
     * The problems of the links of a page as it's edited (read from the element, not from the table): for the sidebar panel
     * and the check on publish. Same reasons as the report; anchors on the page itself are the ones it has now.
     *
     * @param array<array<string, mixed>> $links link records from the Extractor
     * @param string[] $pageAnchors
     * @return array<array<string, mixed>> the links with a problem, with reason and severity
     */
    public function evaluate(array $links, array $pageAnchors, int $pageId, int $siteId): array
    {
        if (!$links) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map(
            fn(array $link) => in_array($link['kind'], LinkParser::ELEMENT_KINDS, true) ? (int)$link['targetId'] : null,
            $links,
        ))));
        $elements = [];
        $sites = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = (new Query())
                ->select(['e.id', 'e.dateDeleted', 'e.enabled', 'e.archived', 'en.postDate', 'en.expiryDate'])
                ->from(['e' => Table::ELEMENTS])
                ->leftJoin(['en' => Table::ENTRIES], '[[en.id]] = [[e.id]]')
                ->where(['e.id' => $chunk])
                ->all();
            foreach ($rows as $row) {
                $elements[(int)$row['id']] = $row;
            }
            $rows = (new Query())
                ->select(['id', 'elementId', 'siteId', 'enabled', 'uri', 'title'])
                ->from(Table::ELEMENTS_SITES)
                ->where(['elementId' => $chunk])
                ->all();
            foreach ($rows as $row) {
                $sites[$row['elementId'] . ':' . $row['siteId']] = $row;
            }
        }

        $hosts = array_values(array_unique(array_filter(array_column($links, 'host'))));
        $unresolved = $hosts ? self::unresolvedHosts((new Query())->select(['host', 'resolves'])->from(Hosts::TABLE)->where(['host' => $hosts])->all()) : [];

        $hashes = [];
        foreach ($links as $link) {
            if ($link['kind'] === LinkParser::KIND_EXTERNAL && $link['host'] !== null) {
                $hashes[] = sha1(LinkParser::normalizeUrl($link['url']));
            }
        }
        $external = LinkChecker::getInstance()->getUrls()->reported($hashes);

        $rows = [];
        foreach ($links as $link) {
            $element = $elements[(int)$link['targetId']] ?? null;
            $check = $link['kind'] === LinkParser::KIND_EXTERNAL && $link['host'] !== null
                ? ($external[sha1(LinkParser::normalizeUrl($link['url']))] ?? null)
                : null;
            $site = $sites[$link['targetId'] . ':' . $link['targetSiteId']] ?? null;
            $rows[] = self::stateRow($link, $pageId, $siteId, $element, $site, isset($link['host']) && isset($unresolved[$link['host']]), $check);
        }
        return $this->judge($rows, [$pageId . ':' . $siteId => array_fill_keys($pageAnchors, true)]);
    }

    /**
     * The domains that don't exist, from rows of the hosts table: [host => true].
     *
     * @param array<array{host: string, resolves: mixed}> $rows
     * @return array<string, true>
     */
    public static function unresolvedHosts(array $rows): array
    {
        $unresolved = [];
        foreach ($rows as $row) {
            if (!$row['resolves']) {
                $unresolved[(string)$row['host']] = true;
            }
        }
        return $unresolved;
    }

    /**
     * A link as it's edited with its target's current state, in the shape of a row of the report's query.
     *
     * @param array<string, mixed> $link a link record from the Extractor
     * @param array<string, mixed>|null $element the target element (elements + entries columns)
     * @param array<string, mixed>|null $site the target's row in elements_sites, in the link's site
     * @param array<string, mixed>|null $check the last check of the external page (Urls::reported())
     * @return array<string, mixed>
     */
    public static function stateRow(array $link, int $pageId, int $siteId, ?array $element, ?array $site, bool $hostUnresolved, ?array $check): array
    {
        return array_merge($link, [
            'sourceId' => $pageId,
            'siteId' => $siteId,
            'targetElementId' => $element['id'] ?? null,
            'targetDeleted' => $element['dateDeleted'] ?? null,
            'targetEnabled' => $element['enabled'] ?? null,
            'targetArchived' => $element['archived'] ?? null,
            'targetSiteRow' => $site['id'] ?? null,
            'targetSiteEnabled' => $site['enabled'] ?? null,
            'targetUri' => $site['uri'] ?? null,
            'targetTitle' => $site['title'] ?? null,
            'targetPostDate' => $element['postDate'] ?? null,
            'targetExpiryDate' => $element['expiryDate'] ?? null,
            'hostResolves' => $hostUnresolved ? false : null,
            'externalStatus' => $check['status'] ?? null,
            'externalCode' => $check['httpCode'] ?? null,
            'externalFinalUrl' => $check['finalUrl'] ?? null,
        ]);
    }

    /**
     * The links with a problem among these rows (a link with its target's state), with reason and severity.
     *
     * @param array<array<string, mixed>> $rows
     * @param array<string, array<string, true>> $anchorOverrides the anchors of pages as they're edited, 'pageId:siteId'
     * @return array<array<string, mixed>>
     */
    private function judge(array $rows, array $anchorOverrides = []): array
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $settings = LinkChecker::getInstance()->getSettings();
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
            $reason = $this->reason($row, $live, $now);
            if ($reason !== null) {
                $problems[$i] = $reason;
            } elseif ($settings->checkAnchors && ($target = $this->anchorTarget($row, $live)) !== null) {
                $anchorChecks[$i] = $target;
            }
        }
        if ($anchorChecks) {
            $anchors = array_merge($this->anchors(array_values($anchorChecks)), $anchorOverrides);
            foreach ($anchorChecks as $i => [$pageId, $siteId, $anchor]) {
                $defined = $anchors[$pageId . ':' . $siteId] ?? null;
                // Only for a page that was read (its anchors are known), and an anchor the templates don't render
                if ($defined !== null && !isset($defined[$anchor]) && !$settings->isKnownAnchor($anchor)) {
                    $problems[$i] = 'anchor-missing';
                }
            }
        }

        $ignores = LinkChecker::getInstance()->getIgnores();
        $result = [];
        foreach ($rows as $i => $row) {
            if (!isset($problems[$i]) || $ignores->isIgnored((string)$row['url'])) {
                continue;
            }
            $row['reason'] = $problems[$i];
            $row['severity'] = self::REASONS[$problems[$i]];
            $result[] = $row;
        }
        return $result;
    }

    /**
     * The reason in words, for editors.
     */
    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'deleted' => Craft::t('link-checker', 'Deleted'),
            'trashed' => Craft::t('link-checker', 'In the trash'),
            'disabled' => Craft::t('link-checker', 'Disabled'),
            'not-in-site' => Craft::t('link-checker', 'Not in this site'),
            'expired' => Craft::t('link-checker', 'Expired'),
            'no-url' => Craft::t('link-checker', 'Has no page of its own'),
            'page-not-found' => Craft::t('link-checker', 'Page not found'),
            'redirected' => Craft::t('link-checker', 'Redirected: better to link to the page itself'),
            'domain-not-found' => Craft::t('link-checker', 'The website’s domain doesn’t exist'),
            'external-not-found' => Craft::t('link-checker', 'The page doesn’t exist any more'),
            'moved-permanently' => Craft::t('link-checker', 'Moved permanently: update the link'),
            'anchor-missing' => Craft::t('link-checker', 'Anchor not found on the page'),
            'pending' => Craft::t('link-checker', 'Not published yet'),
            'deleted-fallback' => Craft::t('link-checker', 'Deleted, but the link still works through its saved URL'),
            'other-site' => Craft::t('link-checker', 'In another language'),
            'invalid-email' => Craft::t('link-checker', 'Invalid email address'),
            'invalid-phone' => Craft::t('link-checker', 'Invalid phone number'),
            default => $reason,
        };
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
    private function reason(array $row, array $live, string $now): ?string
    {
        return self::reasonFor($row, $live, $now, LinkChecker::getInstance()->getSettings(), fn(array $row) => $this->redirected($row));
    }

    /**
     * Why a link (a row with its target's state) has a problem, or null when it has none (anchors aside).
     *
     * @param array<string, mixed> $row
     * @param array<string, int> $live 'siteId:uri' => element id of the live pages among the rows' URIs
     * @param callable(array<string, mixed>): bool $redirected whether a redirect sends an own-site URL on
     */
    public static function reasonFor(array $row, array $live, string $now, Settings $settings, callable $redirected): ?string
    {
        $crossSite = $settings->crossSiteLinks === 'warning';
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
        if ($row['kind'] === LinkParser::KIND_EXTERNAL) {
            if ($row['hostResolves'] !== null && !$row['hostResolves'] && $settings->checkDomains) {
                return 'domain-not-found';
            }
            if ($settings->checkExternal && ($row['externalStatus'] ?? null) === Urls::BROKEN) {
                return 'external-not-found';
            }
            if ($settings->checkExternal && ($row['externalStatus'] ?? null) === Urls::REDIRECT) {
                return 'moved-permanently';
            }
            return null;
        }
        if ($row['kind'] === LinkParser::KIND_URL) {
            if ($row['issue'] === 'route') {
                return null;
            }
            if (isset($live[$row['targetSiteId'] . ':' . strtolower((string)$row['uri'])])) {
                return null;
            }
            return $redirected($row) ? 'redirected' : 'page-not-found';
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
     * The global sets the user may edit.
     *
     * @return int[]
     */
    private function editableGlobalSetIds(User $user): array
    {
        $ids = [];
        foreach (Craft::$app->getGlobals()->getAllSets() as $set) {
            if ($user->can("editGlobalSet:$set->uid")) {
                $ids[] = (int)$set->id;
            }
        }
        return $ids;
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
        return Craft::$app->getElements()->getElementById($sourceId, null, $siteId)?->getCpEditUrl();
    }
}
