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
 * 7 days, one that doesn't after a day). Only in a queue job or a console command, never in an editor's request. The pages
 * themselves aren't requested.
 */
class Hosts extends Component
{
    public const TABLE = '{{%linkchecker_hosts}}';

    /** A domain that always exists: when even this one can't be looked up, the lookups say nothing (no network, a broken resolver) */
    public const CANARY = 'example.com';

    private const RECHECK_OK = 'P7D';
    private const RECHECK_FAILED = 'P1D';

    private bool $queued = false;

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
                ['and', ['h.resolves' => false], ['<', 'h.checkedAt', $failed]],
            ])
            ->column();
    }

    /**
     * Pushes a queue job when domains are due (once per request).
     */
    public function queueIfDue(): void
    {
        if ($this->queued || !LinkChecker::getInstance()->getSettings()->checkDomains) {
            return;
        }
        if ($this->due()) {
            Queue::push(new CheckHostsJob());
            $this->queued = true;
        }
    }

    /**
     * Looks up these domains and stores the results. Returns false (and stores nothing) when the lookups can't be trusted.
     *
     * @param string[] $hosts
     * @param callable|null $progress function(int $done, int $total)
     */
    public function check(array $hosts, ?callable $progress = null): bool
    {
        if (!$hosts) {
            return true;
        }
        if (!self::resolves(self::CANARY)) {
            Craft::warning('Link Checker: DNS lookups fail (also for ' . self::CANARY . '), so no domains were checked.', __METHOD__);
            return false;
        }
        $now = Db::prepareDateForDb(new \DateTime());
        foreach (array_values($hosts) as $i => $host) {
            Db::upsert(self::TABLE, [
                'host' => $host,
                'resolves' => self::resolves($host),
                'checkedAt' => $now,
            ]);
            if ($progress) {
                $progress($i + 1, count($hosts));
            }
        }
        return true;
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
