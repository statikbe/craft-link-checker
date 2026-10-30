<?php

namespace statikbe\linkchecker\services;

use Craft;
use craft\base\Component;
use craft\db\Query;

/**
 * The redirects of a redirect plugin (SEO Fields, Retour): a link to a page that no longer exists but that a redirect sends
 * on still works for visitors, so it isn't broken (a warning: better to link to the page itself).
 */
class Redirects extends Component
{
    /** @var array<array{siteId: int|null, pattern: string, match: string, regex: bool}>|null */
    private ?array $redirects = null;

    /** @var array<string, true>|null exact redirects: 'match|siteId|lowercase pattern' (siteId '' for every site) */
    private ?array $exact = null;

    /** @var array<array{siteId: int|null, pattern: string, match: string, regex: bool}> */
    private array $regex = [];

    /**
     * Whether a redirect catches this URL (absolute) in this site. Exact redirects are looked up, only the regular expressions
     * are tried one by one.
     */
    public function matches(string $url, int $siteId): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }
        $path = '/' . ltrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $candidates = [
            'path' => [$path . $query, rtrim($path, '/') . $query, $path, rtrim($path, '/') ?: '/'],
            'pathWithoutParams' => [$path, rtrim($path, '/') ?: '/'],
            'url' => [explode('#', $url)[0]],
        ];
        $this->index();
        foreach ($candidates as $match => $values) {
            foreach ($values as $candidate) {
                $candidate = mb_strtolower($candidate);
                if (isset($this->exact["$match||$candidate"]) || isset($this->exact["$match|$siteId|$candidate"])) {
                    return true;
                }
            }
        }
        foreach ($this->regex as $redirect) {
            if ($redirect['siteId'] !== null && $redirect['siteId'] !== $siteId) {
                continue;
            }
            foreach ($candidates[$redirect['match']] ?? $candidates['path'] as $candidate) {
                if (@preg_match('`' . str_replace('`', '\`', $redirect['pattern']) . '`i', $candidate) === 1) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Splits the redirects into a lookup of the exact ones and a list of the regular expressions.
     */
    private function index(): void
    {
        if ($this->exact !== null) {
            return;
        }
        $this->exact = [];
        foreach ($this->all() as $redirect) {
            if ($redirect['regex']) {
                $this->regex[] = $redirect;
            } else {
                $match = isset(['path' => 1, 'pathWithoutParams' => 1, 'url' => 1][$redirect['match']]) ? $redirect['match'] : 'path';
                $this->exact[$match . '|' . ($redirect['siteId'] ?? '') . '|' . mb_strtolower($redirect['pattern'])] = true;
            }
        }
    }

    /**
     * @return array<array{siteId: int|null, pattern: string, match: string, regex: bool}>
     */
    private function all(): array
    {
        if ($this->redirects !== null) {
            return $this->redirects;
        }
        $this->redirects = [];
        $db = Craft::$app->getDb();

        // SEO Fields (studioespresso/craft-seo-fields)
        if ($db->tableExists('{{%seofields_redirects}}')) {
            foreach ((new Query())->select(['siteId', 'pattern', 'matchType', 'sourceMatch'])->from('{{%seofields_redirects}}')->all() as $row) {
                $this->redirects[] = [
                    'siteId' => $row['siteId'] !== null ? (int)$row['siteId'] : null,
                    'pattern' => (string)$row['pattern'],
                    'match' => in_array($row['sourceMatch'], ['url', 'pathWithoutParams'], true) ? $row['sourceMatch'] : 'path',
                    'regex' => $row['matchType'] === 'regex',
                ];
            }
        }

        // Retour (nystudio107/craft-retour)
        if ($db->tableExists('{{%retour_static_redirects}}')) {
            $query = (new Query())
                ->select(['siteId', 'redirectSrcUrl', 'redirectSrcMatch', 'redirectMatchType'])
                ->from('{{%retour_static_redirects}}');
            if ($db->columnExists('{{%retour_static_redirects}}', 'enabled')) {
                $query->where(['enabled' => true]);
            }
            foreach ($query->all() as $row) {
                $this->redirects[] = [
                    'siteId' => $row['siteId'] !== null ? (int)$row['siteId'] : null,
                    'pattern' => (string)$row['redirectSrcUrl'],
                    'match' => $row['redirectSrcMatch'] === 'fullurl' ? 'url' : 'path',
                    'regex' => $row['redirectMatchType'] === 'regexmatch',
                ];
            }
        }
        return $this->redirects;
    }
}
