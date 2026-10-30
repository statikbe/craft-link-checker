<?php

namespace statikbe\linkchecker\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Queue;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
use statikbe\linkchecker\helpers\AddressPolicy;
use statikbe\linkchecker\helpers\HeadersReceived;
use statikbe\linkchecker\helpers\LinkParser;
use statikbe\linkchecker\helpers\UnpinnedException;
use statikbe\linkchecker\jobs\CheckUrlsJob;
use statikbe\linkchecker\LinkChecker;

/**
 * Checks external pages over HTTP, in a queue job or a console command, never in an editor's request.
 *
 * - Each URL once, however many pages link to it (keyed by the sha1 of the normalized URL).
 * - HEAD first, a GET (without reading the body) when a server doesn't answer HEAD properly.
 * - Redirects are followed by hand (at most 5), so every step is checked: only http(s) on ports 80 and 443, and never a host
 *   with an address that isn't public (AddressPolicy). Every request goes through curl, pinned to the checked address.
 * - One request at a time, with hostDelay between two requests to the same host.
 * - 404 or 410: a failure. failuresBeforeBroken failures in a row, at least an hour apart, make the link broken.
 * - A permanent redirect (301, 308) to a page that works: a warning, update the link. Not when it only changes http to https,
 *   adds or drops www. or a trailing slash: those are harmless.
 * - Anything else (401, 403, 429, 5xx, a timeout, an SSL error): unknown, never reported. Bot walls and flaky servers
 *   shouldn't make editors distrust the list.
 */
class Urls extends Component
{
    public const TABLE = '{{%linkchecker_urls}}';

    public const OK = 'ok';
    public const FAILING = 'failing';
    public const BROKEN = 'broken';
    public const REDIRECT = 'redirect';
    public const UNKNOWN = 'unknown';

    private const MAX_REDIRECTS = 5;

    /** A failure only counts when the previous check was at least this long ago (seconds; the retry comes after an hour ±10%) */
    private const FAILURE_INTERVAL = 2700;

    private const QUEUED_KEY = 'linkchecker:urls-queued';

    private ?Client $client = null;

    /** @var array<string, float> microtime of the last request per host */
    private array $lastRequest = [];

    /**
     * The external URLs that weren't checked yet or are due again: [['urlHash', 'url', 'host'], …]. Not of skipped hosts, and
     * when domains are checked (checkDomains), only URLs whose domain exists.
     *
     * @return array<array{urlHash: string, url: string, host: string}>
     */
    public function due(bool $all = false): array
    {
        $settings = LinkChecker::getInstance()->getSettings();
        $now = Db::prepareDateForDb(new \DateTime());
        $query = (new Query())
            ->select(['l.urlHash', 'url' => 'min([[l.url]])', 'host' => 'min([[l.host]])'])
            ->from(['l' => Index::TABLE])
            ->leftJoin(['h' => Hosts::TABLE], '[[h.host]] = [[l.host]]')
            ->leftJoin(['u' => self::TABLE], '[[u.urlHash]] = [[l.urlHash]]')
            ->where(['l.kind' => LinkParser::KIND_EXTERNAL])
            ->andWhere(['not', ['l.urlHash' => null]])
            // Domains are looked up first; without that check, a domain that was never looked up counts as existing
            ->andWhere($settings->checkDomains ? ['h.resolves' => true] : ['or', ['h.id' => null], ['h.resolves' => true]])
            ->groupBy(['l.urlHash']);
        if (!$all) {
            $query->andWhere(['or', ['u.id' => null], ['<=', 'u.nextCheckAt', $now]]);
        }
        return array_values(array_filter($query->all(), fn(array $row) => !$settings->skipsHost((string)$row['host'])));
    }

    /**
     * Pushes a queue job when URLs are due, unless one is waiting already (it checks every URL that's due when it starts).
     */
    public function queueIfDue(): void
    {
        $cache = Craft::$app->getCache();
        if (!LinkChecker::getInstance()->getSettings()->checkExternal || $cache->exists(self::QUEUED_KEY)) {
            return;
        }
        if ($this->due() && $cache->add(self::QUEUED_KEY, true, 3600)) {
            Queue::push(new CheckUrlsJob());
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
     * Checks these URLs and stores the results. A URL that was checked since it became due (by another job) is skipped,
     * unless $force.
     *
     * @param array<array{urlHash: string, url: string, host: string}> $items
     * @param callable|null $progress function(int $done, int $total)
     */
    public function check(array $items, ?callable $progress = null, bool $force = false): void
    {
        $items = array_values($items);
        $stored = false;
        foreach ($items as $i => $item) {
            if ($force || $this->isDue($item['urlHash'])) {
                $this->store($item, $this->request(LinkParser::normalizeUrl($item['url'])));
                $stored = true;
            }
            if ($progress) {
                $progress($i + 1, count($items));
            }
        }
        if ($stored) {
            Report::invalidate();
        }
    }

    /**
     * Whether a URL wasn't checked yet or is due again.
     */
    public function isDue(string $urlHash): bool
    {
        $next = (new Query())->select(['nextCheckAt'])->from(self::TABLE)->where(['urlHash' => $urlHash])->scalar();
        return $next === false || $next === null || $next <= Db::prepareDateForDb(new \DateTime());
    }

    /**
     * Requests a URL and follows its redirects.
     *
     * @return array{status: string, httpCode: int|null, error: string|null, finalUrl: string|null}
     */
    public function request(string $url): array
    {
        $permanent = false;
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = AddressPolicy::target($current, [AddressPolicy::class, 'resolve']);
            if (is_string($target)) {
                return $this->result(self::UNKNOWN, null, $target, $current);
            }
            try {
                $response = $this->send('HEAD', $current, $target);
                // Servers that don't answer HEAD properly get a GET (the body isn't read)
                if (in_array($response->getStatusCode(), [400, 403, 405, 500, 501], true)) {
                    $response = $this->send('GET', $current, $target);
                }
            } catch (UnpinnedException) {
                return $this->result(self::UNKNOWN, null, 'unpinned', $current);
            } catch (ConnectException $e) {
                $timeout = str_contains(strtolower($e->getMessage()), 'timed out');
                return $this->result(self::UNKNOWN, null, $timeout ? 'timeout' : 'connection', $current);
            } catch (RequestException $e) {
                return $this->result(self::UNKNOWN, null, 'request', $current);
            } catch (\Throwable $e) {
                return $this->result(self::UNKNOWN, null, 'error', $current);
            }

            $code = $response->getStatusCode();
            if ($code >= 300 && $code < 400 && $response->hasHeader('Location')) {
                if ($hop === 0 && in_array($code, [301, 308], true)) {
                    $permanent = true;
                }
                $current = $this->resolve($current, $response->getHeaderLine('Location'));
                continue;
            }
            if ($code >= 200 && $code < 300) {
                $moved = $permanent && LinkParser::comparableUrl($current) !== LinkParser::comparableUrl($url);
                return $this->result($moved ? self::REDIRECT : self::OK, $code, null, $current);
            }
            if (in_array($code, [404, 410], true)) {
                return $this->result(self::FAILING, $code, null, $current);
            }
            return $this->result(self::UNKNOWN, $code, null, $current);
        }
        return $this->result(self::UNKNOWN, null, 'too-many-redirects', $current);
    }

    /**
     * The status of these URLs that's reported: broken or redirect, by URL hash.
     *
     * @param string[] $hashes
     * @return array<string, array{status: string, httpCode: int|null, finalUrl: string|null}>
     */
    public function reported(array $hashes): array
    {
        if (!$hashes) {
            return [];
        }
        $rows = (new Query())
            ->select(['urlHash', 'status', 'httpCode', 'finalUrl'])
            ->from(self::TABLE)
            ->where(['urlHash' => array_values(array_unique($hashes)), 'status' => [self::BROKEN, self::REDIRECT]])
            ->all();
        $reported = [];
        foreach ($rows as $row) {
            $reported[$row['urlHash']] = $row;
        }
        return $reported;
    }

    /**
     * The failures in a row after one more 404/410: only counted when the previous check was long enough ago, so two checks
     * close together (two jobs, a deploy) don't make a link broken.
     *
     * @param string|null $previousCheck the previous check's date (UTC, as stored)
     */
    public static function failures(int $failures, ?string $previousCheck, int $now): int
    {
        if ($failures === 0 || $previousCheck === null) {
            return $failures + 1;
        }
        $previous = strtotime($previousCheck . ' UTC');
        return $previous !== false && $now - $previous >= self::FAILURE_INTERVAL ? $failures + 1 : $failures;
    }

    /**
     * @param array{urlHash: string, url: string, host: string} $item
     * @param array{status: string, httpCode: int|null, error: string|null, finalUrl: string|null} $result
     */
    private function store(array $item, array $result): void
    {
        $settings = LinkChecker::getInstance()->getSettings();
        $previous = (new Query())->select(['failures', 'checkedAt'])->from(self::TABLE)->where(['urlHash' => $item['urlHash']])->one();
        $failures = (int)($previous['failures'] ?? 0);
        $status = $result['status'];
        $next = 'P7D';

        if ($status === self::FAILING) {
            $failures = self::failures($failures, $previous['checkedAt'] ?? null, time());
            if ($failures >= $settings->failuresBeforeBroken) {
                $status = self::BROKEN;
                $next = 'P1D';
            } else {
                // Checked again after an hour before it counts
                $next = 'PT1H';
            }
        } elseif ($status === self::UNKNOWN) {
            $next = 'P1D';
        } else {
            $failures = 0;
        }

        $now = new \DateTime();
        $seconds = (new \DateTime())->add(new \DateInterval($next))->getTimestamp() - $now->getTimestamp();
        // ±10%, so checks spread out over time
        $seconds = (int)round($seconds * (0.9 + mt_rand(0, 200) / 1000));
        Db::upsert(self::TABLE, [
            'urlHash' => $item['urlHash'],
            'url' => $item['url'],
            'host' => $item['host'],
            'status' => $status,
            'httpCode' => $result['httpCode'],
            'error' => $result['error'],
            'finalUrl' => $result['finalUrl'] !== null ? mb_substr($result['finalUrl'], 0, 2000) : null,
            'failures' => $failures,
            'checkedAt' => Db::prepareDateForDb($now),
            'nextCheckAt' => Db::prepareDateForDb((clone $now)->modify("+$seconds seconds")),
        ]);
    }

    /**
     * One request, connected to the address that was checked (CURLOPT_RESOLVE), always through curl. The body of a GET isn't
     * read: the request stops once the headers are in. A response from another address (a proxy, a pin that didn't apply)
     * isn't used.
     *
     * @param array{0: string, 1: int, 2: string} $target
     * @throws UnpinnedException
     */
    private function send(string $method, string $url, array $target): ResponseInterface
    {
        [$host, $port, $ip] = $target;
        $wait = ($this->lastRequest[$host] ?? 0) + LinkChecker::getInstance()->getSettings()->hostDelay / 1000 - microtime(true);
        if ($wait > 0) {
            usleep((int)($wait * 1_000_000));
        }
        $this->lastRequest[$host] = microtime(true);

        $connected = null;
        $headers = null;
        $options = self::requestOptions($method, $target, function(?string $primaryIp) use (&$connected) {
            $connected = $primaryIp;
        }, function(ResponseInterface $response) use (&$headers) {
            $headers = $response;
        });
        try {
            $response = $this->client()->request($method, $url, $options);
        } catch (RequestException $e) {
            if (!$e->getPrevious() instanceof HeadersReceived || $headers === null) {
                throw $e;
            }
            $response = $headers;
        }
        if (!AddressPolicy::sameAddress($connected, $ip)) {
            throw new UnpinnedException();
        }
        return $response;
    }

    /**
     * The options of one request: pinned to the checked address, without streaming (which would bypass curl), and for a GET
     * stopped once the headers are in.
     *
     * @param array{0: string, 1: int, 2: string} $target
     * @param callable(string|null): void $onConnected gets the address the request connected to
     * @param callable(ResponseInterface): void $onHeaders gets the response of a GET before its body
     * @return array<string, mixed>
     */
    public static function requestOptions(string $method, array $target, callable $onConnected, callable $onHeaders): array
    {
        [$host, $port, $ip] = $target;
        $options = [
            // An IP address in the URL needs no pin: curl connects to it
            'curl' => filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) ? [] : [CURLOPT_RESOLVE => [AddressPolicy::pin($host, $port, $ip)]],
            'on_stats' => function(TransferStats $stats) use ($onConnected) {
                $onConnected($stats->getHandlerStats()['primary_ip'] ?? null);
            },
        ];
        if ($method === 'GET') {
            $options['on_headers'] = function(ResponseInterface $response) use ($onHeaders) {
                $onHeaders($response);
                throw new HeadersReceived();
            };
        }
        return $options;
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $settings = LinkChecker::getInstance()->getSettings();
            $site = Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '';
            $this->client = self::createClient($settings->timeout, 'StatikLinkChecker/' . LinkChecker::getInstance()->getVersion() . ' (+' . $site . ')');
        }
        return $this->client;
    }

    /**
     * A client that sends every request through curl (Guzzle's stream handler ignores CURLOPT_RESOLVE), without a proxy and
     * without following redirects (each step is checked).
     */
    public static function createClient(int $timeout, string $userAgent): Client
    {
        return new Client([
            'handler' => HandlerStack::create(new CurlHandler()),
            'timeout' => $timeout,
            'connect_timeout' => min(5, $timeout),
            'allow_redirects' => false,
            'http_errors' => false,
            'proxy' => '',
            'headers' => [
                'User-Agent' => $userAgent,
                'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
            ],
        ]);
    }

    /**
     * A redirect's Location against the URL it came from.
     */
    private function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');
        return $origin . $dir . $location;
    }

    /**
     * @return array{status: string, httpCode: int|null, error: string|null, finalUrl: string|null}
     */
    private function result(string $status, ?int $code, ?string $error, ?string $finalUrl): array
    {
        return ['status' => $status, 'httpCode' => $code, 'error' => $error, 'finalUrl' => $finalUrl];
    }
}
