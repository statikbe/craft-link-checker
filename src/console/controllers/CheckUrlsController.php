<?php

namespace statikbe\linkchecker\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use statikbe\linkchecker\LinkChecker;
use yii\console\ExitCode;

/**
 * Checks the external links that are due (or all of them), now.
 *
 *   php craft link-checker/check-urls          the ones that are due
 *   php craft link-checker/check-urls --all    every external link again
 */
class CheckUrlsController extends Controller
{
    public $defaultAction = 'index';

    /** @var bool Check every external link, not only the ones that are due */
    public bool $all = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['all']);
    }

    public function actionIndex(): int
    {
        $urls = LinkChecker::getInstance()->getUrls();
        $due = $urls->due($this->all);
        if (!$due) {
            $this->stdout("No external links are due.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }
        Console::startProgress(0, count($due), 'Checking external links: ');
        $urls->check($due, fn(int $done, int $total) => Console::updateProgress($done, $total));
        Console::endProgress();
        $this->stdout(sprintf("%d external links checked.\n", count($due)), Console::FG_GREEN);
        return ExitCode::OK;
    }
}
