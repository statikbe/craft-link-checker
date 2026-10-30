<?php

namespace statikbe\linkchecker\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use statikbe\linkchecker\jobs\CheckHostsJob;
use statikbe\linkchecker\LinkChecker;

/**
 * Whether the domains of external links exist: a DNS lookup per domain, cached (a domain that exists is checked again after
 * 7 days, one that doesn't after a day). A domain only counts as not existing after two failed lookups at least an hour
 * apart: one failure can be a resolver that timed out. Only in a queue job or a console command, never in an editor's
 * request. The pages themselves aren't requested.
 *
 * Every domain has a row from the moment a page with it is read (checkedAt null, due at once); the queue job takes the due
 * rows in order of their id, so it carries no list.
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

    /** How long a host that refuses link checkers is left alone */
    private const REFUSED_FOR = 'P14D';

    /**
     * The domains that weren't looked up yet or are due again, and that a page still links to. Ordered by id.
     */
    public function dueQuery(): Query
    {
        return (new Query())
            ->from(['h' => self::TABLE])
            ->where(['<=', 'h.nextCheckAt', Db::prepareDateForDb(new \DateTime())])
            ->andWhere(['exists', (new Query())->from(['l' => Index::TABLE])->where('[[l.host]] = [[h.host]]')])
            ->orderBy(['h.id' => SORT_ASC]);
    }

    /**
     * The domains that are due (dueQuery()), after a row id: [['id', 'host'], …].
     *
     * @return array<array{id: int|string, host: string}>
     */
    public function due(int $afterId = 0, ?int $limit = null): array
    {
        return $this->dueQuery()->select(['h.id', 'h.host'])->andWhere(['>', 'h.id', $afterId])->limit($limit)->all();
    }

    /**
     * Adds a row for the domains of a page that don't have one yet, due at once.
     *
     * @param string[] $hosts
     */
    public function addHosts(array $hosts): void
    {
        $now = Db::prepareDateForDb(new \DateTime());
        foreach (array_chunk($hosts, 500) as $chunk) {
            $new = array_values(array_diff($chunk, (new Query())->select(['host'])->from(self::TABLE)->where(['host' => $chunk])->column()));
            if (!$new) {
                continue;
            }
            try {
                Db::batchInsert(self::TABLE, ['host', 'resolves', 'failures', 'nextCheckAt'], array_map(fn(string $host) => [$host, true, 0, $now], $new));
            } catch (\Throwable) {
                // Another job added one of them meanwhile
                foreach ($new as $host) {
                    Db::upsert(self::TABLE, ['host' => $host, 'resolves' => true, 'failures' => 0, 'nextCheckAt' => $now], false);
                }
            }
        }
    }

    /**
     * Pushes a queue job when domains are due, unless one is waiting or running.
     */
    public function queueIfDue(): void
    {
        $cache = Craft::$app->getCache();
        if (!LinkChecker::getInstance()->getSettings()->checkDomains || $cache->exists(self::QUEUED_KEY)) {
            return;
        }
        if ($this->dueQuery()->exists() && $cache->add(self::QUEUED_KEY, true, 3600)) {
            LinkChecker::push(new CheckHostsJob());
        }
    }

    /**
     * A batch of the job started: it's still running (the key expires an hour after the last batch, when a job died).
     */
    public function keepQueued(): void
    {
        Craft::$app->getCache()->set(self::QUEUED_KEY, true, 3600);
    }

    /**
     * The job is done: domains that became due since get a new one.
     */
    public function jobFinished(): void
    {
        Craft::$app->getCache()->delete(self::QUEUED_KEY);
        $this->queueIfDue();
    }

    /**
     * A page of a host answered: 401, 403 or 429 ($refused) REFUSALS times in a row leaves the host alone for two weeks,
     * any other answer resets the count.
     */
    public function answered(string $host, bool $refused): void
    {
        if ($host === '') {
            return;
        }
        $refusals = (int)(new Query())->select(['refusals'])->from(self::TABLE)->where(['host' => $host])->scalar();
        if (!$refused) {
            if ($refusals > 0) {
                Db::update(self::TABLE, ['refusals' => 0, 'refusedUntil' => null], ['host' => $host], [], false);
            }
            return;
        }
        $refusals = min($refusals + 1, 100);
        $until = $refusals >= Urls::REFUSALS ? Db::prepareDateForDb((new \DateTime())->add(new \DateInterval(self::REFUSED_FOR))) : null;
        Db::update(self::TABLE, ['refusals' => $until !== null ? 0 : $refusals, 'refusedUntil' => $until], ['host' => $host], [], false);
    }

    /**
     * Removes the domains no page links to any more, a month after their last lookup.
     */
    public function prune(): int
    {
        $before = Db::prepareDateForDb((new \DateTime())->sub(new \DateInterval('P30D')));
        $ids = (new Query())
            ->select(['h.id'])
            ->from(['h' => self::TABLE])
            ->where(['not exists', (new Query())->from(['l' => Index::TABLE])->where('[[l.host]] = [[h.host]]')])
            ->andWhere(['or', ['h.checkedAt' => null], ['<', 'h.checkedAt', $before]])
            ->column();
        foreach (array_chunk($ids, 500) as $chunk) {
            Db::delete(self::TABLE, ['id' => $chunk]);
        }
        return count($ids);
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
     * Looks up these domains and stores the results (the console commands). Returns false (and stores nothing) when the
     * lookups can't be trusted. A domain a job looked up since it became due is skipped.
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
        foreach ($hosts as $i => $host) {
            $this->checkOne($host);
            if ($progress) {
                $progress($i + 1, count($hosts));
            }
        }
        Report::flush();
        return true;
    }

    /**
     * Looks up one domain and stores the result, unless another job looked it up since it became due. Only when lookups
     * work (lookupsWork()).
     */
    public function checkOne(string $host): void
    {
        if ($this->isDue($host)) {
            $this->store($host, self::resolves($host));
        }
    }

    /**
     * Whether a domain wasn't looked up yet, or is due again.
     */
    public function isDue(string $host): bool
    {
        $next = (new Query())->select(['nextCheckAt'])->from(self::TABLE)->where(['host' => $host])->scalar();
        return $next === false || $next === null || $next <= Db::prepareDateForDb(new \DateTime());
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
        $found = $resolves || $failures < self::FAILURES;
        $interval = self::recheckInterval($found, $failures);
        // The report only changes when a domain is found missing, or found again
        if ($previous !== null && $previous !== false && (bool)$previous['resolves'] !== $found) {
            Report::markDirty();
        }
        Db::upsert(self::TABLE, [
            'host' => $host,
            'resolves' => $found,
            'failures' => min($failures, 100),
            'checkedAt' => Db::prepareDateForDb(new \DateTime()),
            'nextCheckAt' => Db::prepareDateForDb((new \DateTime())->add(new \DateInterval($interval))),
        ]);
    }

    /**
     * When a domain is looked up again: a week after it was found, an hour after a first failed lookup, a day after it was
     * found missing.
     */
    public static function recheckInterval(bool $resolves, int $failures): string
    {
        return match (true) {
            !$resolves => self::RECHECK_FAILED,
            $failures > 0 => self::RECHECK_FIRST_FAILURE,
            default => self::RECHECK_OK,
        };
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
