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

    /**
     * Whether a redirect catches this URL (absolute) in this site.
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
        foreach ($this->all() as $redirect) {
            if ($redirect['siteId'] !== null && $redirect['siteId'] !== $siteId) {
                continue;
            }
            foreach ($candidates[$redirect['match']] ?? $candidates['path'] as $candidate) {
                if ($redirect['regex']
                    ? @preg_match('`' . str_replace('`', '\`', $redirect['pattern']) . '`i', $candidate) === 1
                    : strcasecmp($redirect['pattern'], $candidate) === 0) {
                    return true;
                }
            }
        }
        return false;
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
