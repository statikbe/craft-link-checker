<?php

namespace statikbe\linkchecker\models;

use craft\base\Model;

/**
 * Settings, in config/link-checker.php.
 */
class Settings extends Model
{
    /**
     * @var string[]|string The sections whose entries are checked (handles), or '*' for every section. Nested entries (Matrix,
     * Content Block, CKEditor) are checked as part of the page they're on.
     */
    public array|string $sections = '*';

    /**
     * @var bool Check that the domain of an external link exists (a DNS lookup in a queue job, cached per domain): a typo or an
     * expired domain is a broken link. The pages themselves aren't requested.
     */
    public bool $checkDomains = true;

    /**
     * @var bool Check external pages over HTTP, in a queue job: a page that answers 404 or 410 twice (at least an hour apart)
     * is broken, a permanent redirect is a warning. Needs outbound HTTP from the server; switch it off per environment when
     * that's not allowed.
     */
    public bool $checkExternal = true;

    /**
     * @var string[] Hosts whose pages aren't requested: sites that block link checkers (and would always look broken). Their
     * domain is still checked. www. is ignored.
     */
    public array $skipHosts = ['facebook.com', 'instagram.com', 'linkedin.com', 'x.com', 'twitter.com', 'tiktok.com'];

    /** @var int Seconds to wait for an external page */
    public int $timeout = 10;

    /** @var int Milliseconds between two requests to the same host */
    public int $hostDelay = 1000;

    /** @var int How many 404/410 answers in a row (at least an hour apart) make an external link broken */
    public int $failuresBeforeBroken = 2;

    /** @var bool Also check the global sets (a footer, site settings) */
    public bool $checkGlobals = true;

    /**
     * @var string|false What happens when an entry is published with a broken link (warnings never count):
     * - 'warn' (default): it's saved, with a warning listing the broken links;
     * - 'block': it isn't saved; the error is shown on the row, the block or column and its field;
     * - false: no check.
     * A link can break without the page being edited (someone deletes the target), so 'warn' is the softer default.
     */
    public string|false $mode = 'warn';

    /** @var bool The "Links" panel in the sidebar of entries: the problems of the page as it's edited */
    public bool $panel = true;

    /** @var string[] Field handles that are never read for links, e.g. a field that only stores an embed code */
    public array $excludeFields = [];

    /**
     * @var string[] Other hosts that are the same website as the sites' base URLs: an old domain, a staging host. A link to
     * them is checked as a link to the site. www and non-www are already the same.
     */
    public array $internalHosts = [];

    /**
     * @var string[] Links to ignore: regular expressions matched against the stored value (a URL, mailto:, a reference tag),
     * e.g. '#^https?://(www\.)?example\.com/#'.
     */
    public array $ignorePatterns = [];

    /**
     * @var string What a link to an entry in another site (language) is: 'ok' (default: translated pages often keep the links
     * of the page they were copied from) or 'warning'.
     */
    public string $crossSiteLinks = 'ok';

    /**
     * @var string[] Anchors that the templates render on every page, so they aren't in the content (a skip link, a form):
     * a link to them is never reported as an anchor that's not found. Strings, or regular expressions between # signs.
     */
    public array $knownAnchors = [];

    /** @var bool Report links to an anchor (#…) that isn't on the target page (as warnings) */
    public bool $checkAnchors = true;

    /** @var bool Report mailto: and tel: links whose address or number isn't valid (as warnings) */
    public bool $checkEmailAndPhone = true;

    protected function defineRules(): array
    {
        return [
            [['excludeFields', 'internalHosts', 'ignorePatterns', 'knownAnchors', 'skipHosts'], 'safe'],
            [['checkExternal'], 'boolean'],
            [['timeout', 'hostDelay', 'failuresBeforeBroken'], 'integer', 'min' => 1],
            [['checkAnchors', 'checkGlobals', 'panel', 'checkDomains'], 'boolean'],
            [['crossSiteLinks'], 'in', 'range' => ['ok', 'warning']],
            [['checkEmailAndPhone'], 'boolean'],
        ];
    }

    /**
     * Whether entries of this section are checked.
     */
    public function checksSection(string $handle): bool
    {
        return $this->sections === '*' || in_array($handle, (array)$this->sections, true);
    }

    /**
     * Whether an anchor is rendered by the templates on every page (knownAnchors).
     */
    public function isKnownAnchor(string $anchor): bool
    {
        foreach ($this->knownAnchors as $known) {
            if ($known === $anchor || (str_starts_with($known, '#') && strlen($known) > 2 && @preg_match($known, $anchor) === 1)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the pages of a host aren't requested (skipHosts).
     */
    public function skipsHost(string $host): bool
    {
        $host = strtolower($host);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        foreach ($this->skipHosts as $skip) {
            $skip = strtolower($skip);
            if ($host === $skip || str_ends_with($host, '.' . $skip)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a link value matches an ignore pattern.
     */
    public function isIgnored(string $value): bool
    {
        foreach ($this->ignorePatterns as $pattern) {
            if (@preg_match($pattern, $value) === 1) {
                return true;
            }
        }
        return false;
    }
}
