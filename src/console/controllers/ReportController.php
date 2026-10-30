<?php

namespace statikbe\linkchecker\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use statikbe\linkchecker\LinkChecker;
use yii\console\ExitCode;

/**
 * Lists the pages with broken links.
 *
 *   php craft link-checker/report              the broken links
 *   php craft link-checker/report --warnings   the warnings too
 */
class ReportController extends Controller
{
    public $defaultAction = 'index';

    /** @var bool Also list the warnings */
    public bool $warnings = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['warnings']);
    }

    public function actionIndex(): int
    {
        $report = LinkChecker::getInstance()->getReport();
        $pages = $report->pages($this->warnings);
        $editUrls = $report->editUrls($pages);
        foreach ($pages as $page) {
            $site = Craft::$app->getSites()->getSiteById($page['siteId'], true)->handle ?? $page['siteId'];
            $this->stdout(sprintf("%s (%s, %s)\n", $page['title'], $site, $editUrls[$page['sourceId'] . '-' . $page['siteId']]), Console::FG_YELLOW);
            foreach ($page['links'] as $link) {
                $this->stdout(sprintf("  - [%s] %s: %s (%s)\n", $link['severity'], $link['location'], $link['label'] ?? $link['url'], $link['reason']));
            }
        }
        $counts = $report->counts();
        $this->stdout(sprintf("\n%d pages with %d broken links; %d warnings.\n", $counts['pages'], $counts['broken'], $counts['warnings']), $counts['broken'] ? Console::FG_RED : Console::FG_GREEN);
        return ExitCode::OK;
    }
}
