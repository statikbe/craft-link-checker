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

    /** @var bool Don't check the external pages (only read the links and look up the domains) */
    public bool $skipExternal = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['queue', 'skipExternal']);
    }

    public function actionIndex(): int
    {
        if ($this->queue) {
            Queue::push(new RebuildJob());
            $this->stdout("Queued: reading the links of every page.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $index = LinkChecker::getInstance()->getIndex();
        $ids = RebuildJob::ids();
        $total = count($ids);
        Console::startProgress(0, $total, 'Reading the links of every page: ');
        $failed = [];
        foreach ($ids as $i => $id) {
            try {
                $index->index((int)$id);
            } catch (\Throwable $e) {
                $failed[$id] = $e->getMessage();
                Craft::error("Couldn't read the links of element {$id}: {$e->getMessage()}", __METHOD__);
            }
            if ($i % 50 === 0) {
                Craft::$app->getElements()->invalidateAllCaches();
                gc_collect_cycles();
            }
            Console::updateProgress($i + 1, $total);
        }
        Console::endProgress();
        $this->stdout("$total pages read.\n", Console::FG_GREEN);
        foreach ($failed as $id => $message) {
            $this->stderr("Couldn't read element $id: $message\n", Console::FG_RED);
        }

        $plugin = LinkChecker::getInstance();
        if ($plugin->getSettings()->checkDomains) {
            $due = $plugin->getHosts()->due();
            if ($due) {
                Console::startProgress(0, count($due), 'Checking the domains of external links: ');
                $ok = $plugin->getHosts()->check($due, fn(int $done, int $total) => Console::updateProgress($done, $total));
                Console::endProgress();
                if (!$ok) {
                    $this->stderr("DNS lookups fail here (also for " . \statikbe\linkchecker\services\Hosts::CANARY . "): the domains weren't checked.\n", Console::FG_YELLOW);
                }
            }
        }
        if ($plugin->getSettings()->checkExternal && !$this->skipExternal) {
            $due = $plugin->getUrls()->due();
            if ($due) {
                Console::startProgress(0, count($due), 'Checking external links: ');
                $plugin->getUrls()->check($due, fn(int $done, int $total) => Console::updateProgress($done, $total));
                Console::endProgress();
            }
        }
        $this->stdout("\n");
        return $this->run('/link-checker/report');
    }
}
