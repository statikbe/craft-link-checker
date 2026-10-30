<?php

namespace statikbe\linkchecker\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\data\LinkData;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\htmlfield\HtmlField;
use craft\htmlfield\HtmlFieldData;
use craft\web\View;
use statikbe\linkchecker\helpers\LinkParser;
use statikbe\linkchecker\LinkChecker;

/**
 * Reads the links of a page in one site: its Link fields, rich text fields (CKEditor, Redactor) and Hyper fields, also in
 * nested entries (Matrix, Content Block, entries in CKEditor), as link records (LinkParser) with where they are.
 */
class Extractor extends Component
{
    /** How deep nested entries are followed */
    private const MAX_DEPTH = 6;

    /** @var array<array{id: int, baseUrl: string}>|null */
    private ?array $sites = null;

    /** @var array<string, true> the anchors of the page being read */
    private array $anchors = [];

    /** @var array<string, bool> whether a site URI is served by a route or a template (isRoute()), per URI */
    private array $routes = [];

    /**
     * The links of a page in its site, and the anchors it defines (ids in its content, Entry Anchors fields).
     *
     * @return array{links: array<array<string, mixed>>, anchors: string[]} link records with sourceId, siteId, elementId,
     *   fieldId, location and label, and (not stored) the element, its field handle and the chain of nested entries from
     *   the page down to it: [['element' => …, 'field' => handle of the field the next one is in], …]
     */
    public function extract(ElementInterface $page): array
    {
        $links = [];
        $this->anchors = [];
        $this->walk($page, $page, [], [], $links, 0);
        return ['links' => $links, 'anchors' => array_keys($this->anchors)];
    }

    /**
     * @param string[] $path
     * @param array<array{element: ElementInterface, field: string}> $chain
     * @param array<array<string, mixed>> $links
     */
    private function walk(ElementInterface $page, ElementInterface $element, array $path, array $chain, array &$links, int $depth): void
    {
        $settings = LinkChecker::getInstance()->getSettings();
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (in_array($field->handle, $settings->excludeFields, true)) {
                continue;
            }
            $value = $element->getFieldValue($field->handle);
            $name = Craft::t('site', $field->name);

            // An anchor field (Entry Anchors): the id the templates give the block's title
            if (is_object($value) && method_exists($value, 'getAnchor')) {
                $anchor = (string)$value->getAnchor();
                if ($anchor !== '') {
                    $this->anchors[$anchor] = true;
                }
            }

            if ($field instanceof Matrix) {
                if ($depth >= self::MAX_DEPTH) {
                    continue;
                }
                foreach ($this->entries($value) as $i => $entry) {
                    $label = sprintf('%s %d (%s)', $name, $i + 1, Craft::t('site', $entry->getType()->name));
                    $this->walk($page, $entry, [...$path, $label], [...$chain, ['element' => $element, 'field' => $field->handle]], $links, $depth + 1);
                }
                continue;
            }
            if ($value instanceof ElementInterface && is_a($field, 'craft\fields\ContentBlock')) {
                $this->walk($page, $value, [...$path, $name], [...$chain, ['element' => $element, 'field' => $field->handle]], $links, $depth + 1);
                continue;
            }

            $location = implode(' › ', [...$path, $name]);
            if ($field instanceof Link) {
                if ($value instanceof LinkData) {
                    $this->addLinkData($page, $element, $field, $location, $value, $links, $chain);
                }
            } elseif ($field instanceof HtmlField) {
                $html = $value instanceof HtmlFieldData ? $value->getRawContent() : (string)$value;
                foreach (LinkParser::htmlIds($html) as $id) {
                    $this->anchors[$id] = true;
                }
                foreach (LinkParser::htmlLinks($html) as $link) {
                    $this->add($page, $element, $field, $location, $link['url'], $link['text'], null, $links, $chain);
                }
                // Entries nested in CKEditor
                if (is_object($value) && method_exists($value, 'getEntries') && $depth < self::MAX_DEPTH) {
                    foreach ($this->entries($value->getEntries()) as $i => $entry) {
                        $label = sprintf('%s (%s)', $name, Craft::t('site', $entry->getType()->name));
                        $this->walk($page, $entry, [...$path, $label], [...$chain, ['element' => $element, 'field' => $field->handle]], $links, $depth + 1);
                    }
                }
            } elseif (is_a($field, 'verbb\hyper\fields\HyperField')) {
                $this->addHyper($page, $element, $field, $location, $this->serialize($field, $value, $element), $links, $chain);
            }
        }
    }

    /**
     * @param array<array<string, mixed>> $links
     * @param array<array{element: ElementInterface, field: string}> $chain
     */
    private function addLinkData(ElementInterface $page, ElementInterface $element, FieldInterface $field, string $location, LinkData $data, array &$links, array $chain): void
    {
        $stored = $data->serialize();
        $raw = (string)($stored['value'] ?? '');
        $raw = match ($stored['type'] ?? '') {
            'email' => str_starts_with(strtolower($raw), 'mailto:') ? $raw : 'mailto:' . $raw,
            'phone' => str_starts_with(strtolower($raw), 'tel:') ? $raw : 'tel:' . $raw,
            'sms' => '',
            default => $raw,
        };
        $this->add($page, $element, $field, $location, $raw, $stored['label'] ?? null, $stored['urlSuffix'] ?? null, $links, $chain);
    }

    /**
     * Hyper stores a list of links: {type: 'verbb\hyper\links\Entry', linkValue: [12] or 'https://…', linkSiteId, linkText}.
     *
     * @param array<array<string, mixed>> $links
     * @param array<array{element: ElementInterface, field: string}> $chain
     */
    private function addHyper(ElementInterface $page, ElementInterface $element, FieldInterface $field, string $location, mixed $stored, array &$links, array $chain): void
    {
        if (!is_array($stored)) {
            return;
        }
        foreach ($stored as $link) {
            if (!is_array($link)) {
                continue;
            }
            $type = strtolower((string)substr(strrchr('\\' . ($link['type'] ?? ''), '\\') ?: '', 1));
            $value = $link['linkValue'] ?? null;
            $id = is_array($value) ? ($value[0] ?? null) : $value;
            $siteId = isset($link['linkSiteId']) && $link['linkSiteId'] !== '' ? (int)$link['linkSiteId'] : null;
            $raw = match ($type) {
                'entry', 'asset', 'category' => is_numeric($id) ? sprintf('{%s:%d%s:url}', $type, $id, $siteId ? '@' . $siteId : '') : '',
                'email' => is_string($value) && $value !== '' ? (str_starts_with(strtolower($value), 'mailto:') ? $value : 'mailto:' . $value) : '',
                'phone' => is_string($value) && $value !== '' ? (str_starts_with(strtolower($value), 'tel:') ? $value : 'tel:' . $value) : '',
                'url' => is_string($value) ? $value : '',
                default => '',
            };
            $this->add($page, $element, $field, $location, $raw, $link['linkText'] ?? null, $link['urlSuffix'] ?? null, $links, $chain);
        }
    }

    /**
     * @param array<array<string, mixed>> $links
     * @param array<array{element: ElementInterface, field: string}> $chain
     */
    private function add(ElementInterface $page, ElementInterface $element, FieldInterface $field, string $location, string $raw, ?string $label, ?string $suffix, array &$links, array $chain): void
    {
        $plugin = LinkChecker::getInstance();
        if ($raw === '' || $plugin->getSettings()->isIgnored($raw)) {
            return;
        }
        $record = LinkParser::classify($raw, (int)$page->siteId, $this->sites(), $plugin->getSettings()->internalHosts);
        if ($record === null) {
            return;
        }
        if (!$plugin->getSettings()->checkEmailAndPhone && in_array($record['kind'], [LinkParser::KIND_EMAIL, LinkParser::KIND_PHONE], true)) {
            $record['issue'] = null;
        }
        // A URL of the site that a route or a template serves, rather than an element
        if ($record['kind'] === LinkParser::KIND_URL && $this->isRoute((string)$record['uri'])) {
            $record['issue'] = 'route';
        }
        $links[] = array_merge($record, [
            'suffix' => $record['suffix'] ?? ($suffix !== null && $suffix !== '' ? $suffix : null),
            'sourceId' => (int)$page->id,
            'siteId' => (int)$page->siteId,
            'elementId' => (int)$element->id,
            'fieldId' => $field->id,
            'location' => mb_substr($location, 0, 1000),
            'label' => $label !== null && $label !== '' ? mb_substr(strip_tags($label), 0, 255) : null,
            'url' => $raw,
            'element' => $element,
            'fieldHandle' => $field->handle,
            'chain' => $chain,
        ]);
    }

    /**
     * Whether a site URI is served by a route (config/routes.php, the project config's routes) or a template in the site's
     * templates folder, rather than by an element: those can't be checked from the database.
     */
    private function isRoute(string $uri): bool
    {
        return $this->routes[$uri] ??= $this->findRoute($uri);
    }

    private function findRoute(string $uri): bool
    {
        if ($uri === '__home__') {
            return false;
        }
        $routes = Craft::$app->getRoutes();
        foreach ([$routes->getConfigFileRoutes(), $routes->getProjectConfigRoutes()] as $set) {
            foreach (array_keys($set) as $pattern) {
                $regex = '#^' . preg_replace('/<\w+:([^>]+)>/', '($1)', preg_replace('/<\w+>/', '[^/]+', (string)$pattern)) . '$#';
                if (@preg_match($regex, $uri) === 1) {
                    return true;
                }
            }
        }
        // A template is only served when no segment of its path starts with an underscore
        foreach (explode('/', $uri) as $segment) {
            if (str_starts_with($segment, '_')) {
                return false;
            }
        }
        return Craft::$app->getView()->doesTemplateExist($uri, View::TEMPLATE_MODE_SITE);
    }

    /**
     * A field's value as it's stored (Hyper: a list of link arrays).
     */
    private function serialize(FieldInterface $field, mixed $value, ElementInterface $element): mixed
    {
        return $field->serializeValue($value, $element);
    }

    /**
     * The enabled entries of a Matrix value or entry query, as the templates render them. Loaded for all the siblings of the
     * element at once (eagerly()): one query per field and level of a page, not per nested entry. Siblings are always in
     * one site: a page is read per site (Index::index()).
     *
     * @return Entry[]
     */
    private function entries(mixed $value): array
    {
        if ($value instanceof ElementQueryInterface) {
            $value = $value->getCachedResult() ?? (clone $value)->eagerly()->all();
        }
        if (!is_iterable($value)) {
            return [];
        }
        // Disabled entries aren't rendered: their links don't count (also when the posted or cached value has them)
        return array_values(array_filter(
            is_array($value) ? $value : iterator_to_array($value),
            fn($e) => $e instanceof Entry && $e->enabled && $e->getEnabledForSite() !== false,
        ));
    }

    /**
     * The sites' base URLs, absolute.
     *
     * @return array<array{id: int, baseUrl: string}>
     */
    private function sites(): array
    {
        if ($this->sites === null) {
            $this->sites = [];
            foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
                $base = $site->getBaseUrl();
                if ($base) {
                    $this->sites[] = ['id' => (int)$site->id, 'baseUrl' => $base];
                }
            }
        }
        return $this->sites;
    }
}
