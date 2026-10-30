<?php

namespace statikbe\linkchecker\utilities;

use Craft;
use craft\base\Utility;
use statikbe\linkchecker\LinkChecker;
use statikbe\linkchecker\services\Report;

/**
 * Utilities → Link Checker: every link with a problem, with filters, a CSV export, and the ignored links. Access is Craft's
 * own utility permission ("Link Checker" under Utilities).
 */
class LinkCheckerUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('link-checker', 'Link Checker');
    }

    public static function id(): string
    {
        return 'link-checker';
    }

    public static function icon(): ?string
    {
        return 'link-slash';
    }

    public static function contentHtml(): string
    {
        $plugin = LinkChecker::getInstance();
        $request = Craft::$app->getRequest();
        $filters = [
            'severity' => (string)$request->getQueryParam('severity', 'broken'),
            'siteId' => (int)$request->getQueryParam('siteId', 0),
            'q' => trim((string)$request->getQueryParam('q', '')),
        ];
        $problems = self::filtered($plugin->getReport()->problems(), $filters);

        $sites = Craft::$app->getSites();
        $editUrls = [];
        foreach ($problems as &$problem) {
            $key = $problem['sourceId'] . '-' . $problem['siteId'];
            $editUrls[$key] ??= $plugin->getReport()->editUrl((int)$problem['sourceId'], (int)$problem['siteId']);
            $problem['editUrl'] = $editUrls[$key];
            $problem['site'] = $sites->getSiteById((int)$problem['siteId'], true)?->getName() ?? '';
            $problem['reasonLabel'] = Report::reasonLabel((string)$problem['reason']);
        }
        unset($problem);

        return Craft::$app->getView()->renderTemplate('link-checker/_utility/index', [
            'problems' => array_slice($problems, 0, 500),
            'total' => count($problems),
            'filters' => $filters,
            'sites' => $sites->getAllSites(true),
            'ignored' => $plugin->getIgnores()->all(),
            'multiSite' => Craft::$app->getIsMultiSite(),
            'checkExternal' => $plugin->getSettings()->checkExternal,
        ]);
    }

    /**
     * The problems that match the filters: severity ('broken', 'warning' or 'all'), site and a search in the page title, the
     * link and its location.
     *
     * @param array<array<string, mixed>> $problems
     * @param array{severity: string, siteId: int, q: string} $filters
     * @return array<array<string, mixed>>
     */
    public static function filtered(array $problems, array $filters): array
    {
        return array_values(array_filter($problems, function(array $problem) use ($filters) {
            if ($filters['severity'] !== 'all' && $problem['severity'] !== $filters['severity']) {
                return false;
            }
            if ($filters['siteId'] && (int)$problem['siteId'] !== $filters['siteId']) {
                return false;
            }
            if ($filters['q'] !== '') {
                $haystack = implode(' ', [$problem['sourceTitle'], $problem['label'], $problem['url'], $problem['location'], $problem['targetTitle'] ?? '']);
                return mb_stripos($haystack, $filters['q']) !== false;
            }
            return true;
        }));
    }
}
