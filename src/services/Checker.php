<?php

namespace statikbe\linkchecker\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use statikbe\linkchecker\LinkChecker;

/**
 * The check when an entry is published (settings → mode): its broken links (not the warnings), read from the entry as it's
 * saved.
 */
class Checker extends Component
{
    /** @var array<string, array<array{field: string, message: string}>> errors per nested entry (uid) of a page that wasn't saved */
    private array $nestedErrors = [];

    /**
     * After an entry is validated (Entry::EVENT_AFTER_VALIDATE). Only in the live scenario of a web request (publishing): not
     * for drafts, autosaves, propagation to other sites, resaving or console commands.
     */
    public function check(Entry $entry): void
    {
        // A nested entry of a page that wasn't saved: the control panel validates its card again to show it, which clears
        // its errors
        if ($entry->uid !== null && isset($this->nestedErrors[$entry->uid])) {
            foreach ($this->nestedErrors[$entry->uid] as $error) {
                $entry->addError($error['field'], $error['message']);
            }
        }

        $plugin = LinkChecker::getInstance();
        $settings = $plugin->getSettings();
        $mode = $settings->mode;
        if (
            !in_array($mode, ['warn', 'block'], true)
            || $entry->getSection() === null
            || !$settings->checksSection($entry->getSection()->handle)
            || $entry->getScenario() !== Entry::SCENARIO_LIVE
            || $entry->propagating
            || $entry->resaving
            || $entry->getIsRevision()
            || Craft::$app->getRequest()->getIsConsoleRequest()
        ) {
            return;
        }

        $broken = $this->broken($entry);
        if (!$broken) {
            return;
        }
        if ($mode === 'warn') {
            if (!$entry->hasErrors()) {
                Craft::$app->getSession()->setError(Craft::t('link-checker', 'This page has {count, plural, =1{# broken link} other{# broken links}}: {links}. See the Links panel.', [
                    'count' => count($broken),
                    'links' => implode(', ', array_map(fn(array $link) => Craft::t('link-checker', '“{link}” ({reason})', [
                        'link' => $link['label'] ?? $link['targetTitle'] ?? $link['url'],
                        'reason' => mb_strtolower(Report::reasonLabel((string)$link['reason'])),
                    ]), $broken)),
                ]));
            }
            return;
        }

        // The control panel draws the blocks from the fields' cached results after a failed save, or queries them again when
        // they weren't posted (unchanged): cache them, so it draws the ones that get the errors
        $cached = false;
        foreach ($broken as $link) {
            $first = $link['chain'][0]['field'] ?? null;
            if ($first !== null) {
                $value = $entry->getFieldValue($first);
                if ($value instanceof ElementQueryInterface && $value->getCachedResult() === null) {
                    $value->setCachedResult((clone $value)->drafts(null)->savedDraftsOnly()->status(null)->limit(null)->all());
                    $cached = true;
                }
            }
        }
        if ($cached) {
            $broken = $this->broken($entry);
        }
        foreach ($broken as $link) {
            $this->placeError($link, $this->message($link));
        }
    }

    /**
     * The broken links of an entry as it is now.
     *
     * @return array<array<string, mixed>>
     */
    private function broken(Entry $entry): array
    {
        $plugin = LinkChecker::getInstance();
        $result = $plugin->getExtractor()->extract($entry);
        $problems = $plugin->getReport()->evaluate($result['links'], $result['anchors'], (int)$entry->getCanonicalId(), (int)$entry->siteId);
        return array_values(array_filter($problems, fn(array $link) => $link['severity'] === Report::BROKEN));
    }

    /**
     * @param array<string, mixed> $link
     */
    public function message(array $link): string
    {
        return Craft::t('link-checker', '{location}: the link “{link}” is broken ({reason})', [
            'location' => $link['location'],
            'link' => $link['label'] ?? $link['targetTitle'] ?? $link['url'],
            'reason' => mb_strtolower(Report::reasonLabel((string)$link['reason'])),
        ]);
    }

    /**
     * Puts a broken link's error where it is, on every level, so the control panel marks it as Craft does for its own
     * errors: the page gets it under the nested block's field (the list at the top links to it), each block or row in between
     * under the field with the next one (Trinity shows it under the row's columns), and the block or column with the link on
     * its field, whose card turns red. A link in a field of the page itself goes on that field.
     *
     * @param array<string, mixed> $link
     */
    private function placeError(array $link, string $message): void
    {
        /** @var array<array{element: ElementInterface, field: string}> $chain */
        $chain = $link['chain'];
        $levels = [...$chain, ['element' => $link['element'], 'field' => (string)$link['fieldHandle']]];
        $last = count($levels) - 1;
        foreach ($levels as $i => ['element' => $element, 'field' => $field]) {
            if ($i === $last) {
                $element->addError($field, $message);
                if ($i > 0 && $element->uid !== null) {
                    $this->nestedErrors[$element->uid][] = ['field' => $field, 'message' => $message];
                }
                return;
            }
            $next = $levels[$i + 1];
            if ($next['element']->uid === null) {
                // Never saved: the error stays on this level's field
                $element->addError($field, $message);
                return;
            }
            if ($i > 0) {
                $element->addError($field, $message);
            }
            $element->addError(sprintf('%s[%s].%s', $field, $next['element']->uid, $next['field']), $message);
        }
    }
}
