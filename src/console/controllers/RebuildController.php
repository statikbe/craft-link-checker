<?php

namespace statikbe\linkchecker\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Queue;
use statikbe\linkchecker\jobs\RebuildJob;
use statikbe\linkchecker\LinkChecker;
use yii\console\ExitCode;

/**
 * Reads the links of every page again, then lists the pages with broken links.
 *
 *   php craft link-checker/rebuild           every page, now
 *   php craft link-checker/rebuild --queue   as a queue job
 */
class RebuildController extends Controller
{
    public $defaultAction = 'index';

    /** @var bool Push a queue job instead of reading the pages now */
    public bool $queue = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['queue']);
    }

    public function actionIndex(): int
    {
        if ($this->queue) {
            Queue::push(new RebuildJob());
            $this->stdout("Queued: reading the links of every page.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $index = LinkChecker::getInstance()->getIndex();
        $ids = RebuildJob::query()->column();
        $total = count($ids);
        Console::startProgress(0, $total, 'Reading the links of every page: ');
        foreach ($ids as $i => $id) {
            $index->index((int)$id);
            if ($i % 50 === 0) {
                Craft::$app->getElements()->invalidateAllCaches();
                gc_collect_cycles();
            }
            Console::updateProgress($i + 1, $total);
        }
        Console::endProgress();
        $this->stdout("$total pages read.\n\n", Console::FG_GREEN);
        return $this->run('/link-checker/report');
    }
}
