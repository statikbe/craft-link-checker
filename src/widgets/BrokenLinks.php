<?php

namespace statikbe\linkchecker\widgets;

use Craft;
use craft\base\Widget;
use statikbe\linkchecker\LinkChecker;
use statikbe\linkchecker\services\Report;

/**
 * Dashboard widget: the pages with broken links, with a link to edit each one and the broken links under a toggle. Only the
 * pages in sections the user may edit.
 */
class BrokenLinks extends Widget
{
    /** @var int Pages shown, the rest is counted */
    public int $limit = 25;

    /** @var bool Also list the pages with only warnings */
    public bool $showWarnings = false;

    public static function displayName(): string
    {
        return Craft::t('link-checker', 'Broken links');
    }

    public static function icon(): ?string
    {
        return 'link-slash';
    }

    public function getTitle(): ?string
    {
        return Craft::t('link-checker', 'Broken links');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['limit'], 'integer', 'min' => 1];
        $rules[] = [['showWarnings'], 'boolean'];
        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('link-checker/_widgets/brokenLinks/settings', ['widget' => $this]);
    }

    public function getBodyHtml(): ?string
    {
        $plugin = LinkChecker::getInstance();
        $report = $plugin->getReport();
        $pages = $report->pages($this->showWarnings, Craft::$app->getUser()->getIdentity());
        $sites = Craft::$app->getSites();
        $shown = array_slice($pages, 0, $this->limit);
        foreach ($shown as &$page) {
            foreach ($page['links'] as &$link) {
                $link['reasonLabel'] = Report::reasonLabel((string)$link['reason']);
            }
            unset($link);
            $page['url'] = $report->editUrl($page['sourceId'], $page['siteId']);
            $page['site'] = $sites->getSiteById($page['siteId'], true)?->getName() ?? '';
        }
        unset($page);

        return Craft::$app->getView()->renderTemplate('link-checker/_widgets/brokenLinks/body', [
            'pages' => $shown,
            'total' => count($pages),
            'brokenTotal' => array_sum(array_column($pages, 'broken')),
            'multiSite' => Craft::$app->getIsMultiSite(),
            'indexed' => !$plugin->getIndex()->isEmpty(),
        ]);
    }
}
