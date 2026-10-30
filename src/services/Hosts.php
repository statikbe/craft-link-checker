<?php

namespace statikbe\linkchecker\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Queue;
use statikbe\linkchecker\jobs\CheckHostsJob;
use statikbe\linkchecker\LinkChecker;

/**
 * Whether the domains of external links exist: a DNS lookup per domain, cached (a domain that exists is checked again after
 * 7 days, one that doesn't after a day). A domain only counts as not existing after two failed lookups at least an hour
 * apart: one failure can be a resolver that timed out. Only in a queue job or a console command, never in an editor's
 * request. The pages themselves aren't requested.
 */
class Hosts extends Component
{
    public const TABLE = '{{%linkchecker_hosts}}';

    /** A domain that always exists: when even this one can't be looked up, the lookups say nothing (no network, a broken resolver) */
    public const CANARY = 'example.com';

    private const RECHECK_OK = 'P7D';
    private const RECHECK_FAILED = 'P1D';
    private const RECHECK_FIRST_FAILURE = 'PT1H';

    /** Failed lookups in a row that make a domain one that doesn't exist */
    private const FAILURES = 2;

    private const QUEUED_KEY = 'linkchecker:hosts-queued';

    /**
     * The domains of external links that weren't looked up yet, or not for a while.
     *
     * @return string[]
     */
    public function due(): array
    {
        $now = new \DateTime();
        $ok = Db::prepareDateForDb((clone $now)->sub(new \DateInterval(self::RECHECK_OK)));
        $failed = Db::prepareDateForDb((clone $now)->sub(new \DateInterval(self::RECHECK_FAILED)));
        $firstFailure = Db::prepareDateForDb((clone $now)->sub(new \DateInterval(self::RECHECK_FIRST_FAILURE)));
        return (new Query())
            ->select(['l.host'])
            ->distinct()
            ->from(['l' => Index::TABLE])
            ->leftJoin(['h' => self::TABLE], '[[h.host]] = [[l.host]]')
            ->where(['l.kind' => 'external'])
            ->andWhere(['not', ['l.host' => null]])
            ->andWhere(['or',
                ['h.id' => null],
                ['and', ['h.resolves' => true], ['<', 'h.checkedAt', $ok]],
                ['and', ['h.resolves' => true], ['>', 'h.failures', 0], ['<', 'h.checkedAt', $firstFailure]],
                ['and', ['h.resolves' => false], ['<', 'h.checkedAt', $failed]],
            ])
            ->column();
    }

    /**
     * Pushes a queue job when domains are due, unless one is waiting already.
     */
    public function queueIfDue(): void
    {
        $cache = Craft::$app->getCache();
        if (!LinkChecker::getInstance()->getSettings()->checkDomains || $cache->exists(self::QUEUED_KEY)) {
            return;
        }
        if ($this->due() && $cache->add(self::QUEUED_KEY, true, 3600)) {
            Queue::push(new CheckHostsJob());
        }
    }

    /**
     * A job started: the next save may push another one.
     */
    public function jobStarted(): void
    {
        Craft::$app->getCache()->delete(self::QUEUED_KEY);
    }

    /**
     * Whether DNS lookups can be trusted here: even a domain that always exists can't be looked up without a network or with
     * a broken resolver.
     */
    public static function lookupsWork(): bool
    {
        return self::resolves(self::CANARY);
    }

    /**
     * Looks up these domains and stores the results. Returns false (and stores nothing) when the lookups can't be trusted.
     * A domain another job looked up since it became due is skipped.
     *
     * @param string[] $hosts
     * @param callable|null $progress function(int $done, int $total)
     */
    public function check(array $hosts, ?callable $progress = null, bool $lookupsChecked = false): bool
    {
        if (!$hosts) {
            return true;
        }
        if (!$lookupsChecked && !self::lookupsWork()) {
            Craft::warning('Link Checker: DNS lookups fail (also for ' . self::CANARY . '), so no domains were checked.', __METHOD__);
            return false;
        }
        $hosts = array_values($hosts);
        $stored = false;
        foreach ($hosts as $i => $host) {
            if ($this->isDue($host)) {
                $this->store($host, self::resolves($host));
                $stored = true;
            }
            if ($progress) {
                $progress($i + 1, count($hosts));
            }
        }
        if ($stored) {
            Report::invalidate();
        }
        return true;
    }

    /**
     * Whether a domain wasn't looked up yet, or is due again.
     */
    public function isDue(string $host): bool
    {
        $row = (new Query())->select(['resolves', 'failures', 'checkedAt'])->from(self::TABLE)->where(['host' => $host])->one();
        if ($row === null || $row === false) {
            return true;
        }
        $interval = match (true) {
            !$row['resolves'] => self::RECHECK_FAILED,
            (int)$row['failures'] > 0 => self::RECHECK_FIRST_FAILURE,
            default => self::RECHECK_OK,
        };
        $since = (new \DateTime())->sub(new \DateInterval($interval));
        return Db::prepareDateForDb($since) > $row['checkedAt'];
    }

    /**
     * Stores a lookup: a failure only counts when the previous one was at least 45 minutes ago.
     */
    private function store(string $host, bool $resolves): void
    {
        $previous = (new Query())->select(['resolves', 'failures', 'checkedAt'])->from(self::TABLE)->where(['host' => $host])->one();
        $failures = 0;
        if (!$resolves) {
            $failures = Urls::failures((int)($previous['failures'] ?? 0), $previous['checkedAt'] ?? null, time());
        }
        Db::upsert(self::TABLE, [
            'host' => $host,
            'resolves' => $resolves || $failures < self::FAILURES,
            'failures' => min($failures, 100),
            'checkedAt' => Db::prepareDateForDb(new \DateTime()),
        ]);
    }

    /**
     * Whether a domain has an address (A or AAAA record, also through a CNAME).
     */
    public static function resolves(string $host): bool
    {
        $host = rtrim(strtolower($host), '.');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return $host !== '';
        }
        if (function_exists('idn_to_ascii')) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
        }
        return checkdnsrr($host . '.', 'A') || checkdnsrr($host . '.', 'AAAA');
    }
}
