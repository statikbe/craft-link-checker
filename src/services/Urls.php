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
use statikbe\linkchecker\helpers\LinkParser;
use statikbe\linkchecker\jobs\CheckUrlsJob;
use statikbe\linkchecker\LinkChecker;

/**
 * Checks external pages over HTTP, in a queue job or a console command, never in an editor's request.
 *
 * - Each URL once, however many pages link to it (keyed by the sha1 of the normalized URL).
 * - HEAD first, a GET (without reading the body) when a server doesn't answer HEAD properly.
 * - Redirects are followed by hand (at most 5), so every step is checked: only http(s) on ports 80 and 443, and never an
 *   address in a private or reserved range (the address that was checked is the one connected to).
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

    private bool $queued = false;

    private ?Client $client = null;

    /** @var array<string, float> microtime of the last request per host */
    private array $lastRequest = [];

    /**
     * The external URLs that weren't checked yet or are due again: [['urlHash', 'url', 'host'], …]. Only URLs whose domain
     * exists (Hosts), not of skipped hosts.
     *
     * @return array<array{urlHash: string, url: string, host: string}>
     */
    public function due(bool $all = false): array
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $query = (new Query())
            ->select(['l.urlHash', 'url' => 'min([[l.url]])', 'host' => 'min([[l.host]])'])
            ->from(['l' => Index::TABLE])
            ->innerJoin(['h' => Hosts::TABLE], '[[h.host]] = [[l.host]]')
            ->leftJoin(['u' => self::TABLE], '[[u.urlHash]] = [[l.urlHash]]')
            ->where(['l.kind' => LinkParser::KIND_EXTERNAL, 'h.resolves' => true])
            ->andWhere(['not', ['l.urlHash' => null]])
            ->groupBy(['l.urlHash']);
        if (!$all) {
            $query->andWhere(['or', ['u.id' => null], ['<=', 'u.nextCheckAt', $now]]);
        }
        $settings = LinkChecker::getInstance()->getSettings();
        return array_values(array_filter($query->all(), fn(array $row) => !$settings->skipsHost((string)$row['host'])));
    }

    /**
     * Pushes a queue job when URLs are due (once per request).
     */
    public function queueIfDue(): void
    {
        if ($this->queued || !LinkChecker::getInstance()->getSettings()->checkExternal) {
            return;
        }
        if ($this->due()) {
            Queue::push(new CheckUrlsJob());
            $this->queued = true;
        }
    }

    /**
     * Checks these URLs and stores the results.
     *
     * @param array<array{urlHash: string, url: string, host: string}> $items
     * @param callable|null $progress function(int $done, int $total)
     */
    public function check(array $items, ?callable $progress = null): void
    {
        foreach (array_values($items) as $i => $item) {
            $this->store($item, $this->request(LinkParser::normalizeUrl($item['url'])));
            if ($progress) {
                $progress($i + 1, count($items));
            }
        }
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
            $target = $this->safeTarget($current);
            if (is_string($target)) {
                return $this->result(self::UNKNOWN, null, $target, $current);
            }
            try {
                $response = $this->send('HEAD', $current, $target);
                // Servers that don't answer HEAD properly get a GET (the body isn't read)
                if (in_array($response->getStatusCode(), [400, 403, 405, 500, 501], true)) {
                    $response = $this->send('GET', $current, $target);
                }
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
     * @param array{urlHash: string, url: string, host: string} $item
     * @param array{status: string, httpCode: int|null, error: string|null, finalUrl: string|null} $result
     */
    private function store(array $item, array $result): void
    {
        $settings = LinkChecker::getInstance()->getSettings();
        $previous = (new Query())->select(['failures'])->from(self::TABLE)->where(['urlHash' => $item['urlHash']])->scalar();
        $failures = (int)$previous;
        $status = $result['status'];
        $next = 'P7D';

        if ($status === self::FAILING) {
            $failures++;
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
     * Where to connect for a URL: [host, port, ip], or a reason not to request it.
     *
     * @return array{0: string, 1: int, 2: string}|string
     */
    private function safeTarget(string $url): array|string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'not-http';
        }
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true)) {
            return 'port';
        }
        $ascii = function_exists('idn_to_ascii') ? (idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host) : $host;
        $ips = filter_var($ascii, FILTER_VALIDATE_IP) ? [$ascii] : (gethostbynamel($ascii) ?: []);
        if (!$ips) {
            return 'dns';
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'private-address';
            }
        }
        return [$ascii, $port, $ips[0]];
    }

    /**
     * @param array{0: string, 1: int, 2: string} $target
     */
    private function send(string $method, string $url, array $target): \Psr\Http\Message\ResponseInterface
    {
        [$host, $port, $ip] = $target;
        $wait = ($this->lastRequest[$host] ?? 0) + LinkChecker::getInstance()->getSettings()->hostDelay / 1000 - microtime(true);
        if ($wait > 0) {
            usleep((int)($wait * 1_000_000));
        }
        $this->lastRequest[$host] = microtime(true);

        return $this->client()->request($method, $url, [
            'stream' => $method === 'GET',
            // Connect to the address that was checked, not to one a second DNS lookup might give
            'curl' => [CURLOPT_RESOLVE => ["$host:$port:$ip"]],
        ]);
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $settings = LinkChecker::getInstance()->getSettings();
            $site = Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '';
            $this->client = Craft::createGuzzleClient([
                'timeout' => $settings->timeout,
                'connect_timeout' => min(5, $settings->timeout),
                'allow_redirects' => false,
                'http_errors' => false,
                'headers' => [
                    'User-Agent' => 'StatikLinkChecker/1.2 (+' . $site . ')',
                    'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
                ],
            ]);
        }
        return $this->client;
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
