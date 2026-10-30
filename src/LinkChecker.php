<?php

namespace statikbe\linkchecker;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\events\DefineHtmlEvent;
use craft\events\ElementEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\helpers\Queue;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\Utilities;
use craft\web\View;
use statikbe\linkchecker\jobs\RebuildJob;
use statikbe\linkchecker\models\Settings;
use statikbe\linkchecker\services\Checker;
use statikbe\linkchecker\services\Extractor;
use statikbe\linkchecker\services\Hosts;
use statikbe\linkchecker\services\Ignores;
use statikbe\linkchecker\services\Index;
use statikbe\linkchecker\services\Redirects;
use statikbe\linkchecker\services\Report;
use statikbe\linkchecker\services\Urls;
use statikbe\linkchecker\utilities\LinkCheckerUtility;
use statikbe\linkchecker\web\assets\cp\LinkCheckerAsset;
use statikbe\linkchecker\widgets\BrokenLinks;
use yii\base\Event;

/**
 * Link Checker: finds the broken links in the content of entries and lists the pages that contain them.
 *
 * @method Settings getSettings()
 */
class LinkChecker extends Plugin
{
    public string $schemaVersion = '1.3.0';

    public static function config(): array
    {
        return [
            'components' => [
                'extractor' => Extractor::class,
                'index' => Index::class,
                'report' => Report::class,
                'redirects' => Redirects::class,
                'checker' => Checker::class,
                'hosts' => Hosts::class,
                'urls' => Urls::class,
                'ignores' => Ignores::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // A saved page (or a block on it) or global set is read again at the end of the request
        foreach ([Entry::class, GlobalSet::class] as $class) {
            Event::on($class, Element::EVENT_AFTER_SAVE, function(ModelEvent $event) {
                /** @var ElementInterface $element */
                $element = $event->sender;
                $this->getIndex()->elementSaved($element);
            });
            Event::on($class, Element::EVENT_AFTER_RESTORE, function(Event $event) {
                /** @var ElementInterface $element */
                $element = $event->sender;
                $this->getIndex()->elementSaved($element);
            });
            Event::on($class, Element::EVENT_AFTER_DELETE, function(Event $event) {
                /** @var ElementInterface $element */
                $element = $event->sender;
                $this->getIndex()->forget($element);
            });
        }

        // A link's target changed (saved, deleted, restored): the cached report is read again
        foreach ([Elements::EVENT_AFTER_SAVE_ELEMENT, Elements::EVENT_AFTER_DELETE_ELEMENT, Elements::EVENT_AFTER_RESTORE_ELEMENT] as $name) {
            Event::on(Elements::class, $name, function(ElementEvent $event) {
                // One cache write: cheap, also during a resave
                if (!$event->element->getIsDraft() && !$event->element->getIsRevision()) {
                    Report::invalidate();
                }
            });
        }

        // The check when an entry is published (settings → mode)
        Event::on(Entry::class, Element::EVENT_AFTER_VALIDATE, function(Event $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            $this->getChecker()->check($entry);
        });

        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = BrokenLinks::class;
        });
        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = LinkCheckerUtility::class;
        });

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, function(RegisterTemplateRootsEvent $event) {
                $event->roots['link-checker'] = __DIR__ . '/templates';
            });

            // The Links panel in an entry's sidebar, under the notes
            Event::on(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function(DefineHtmlEvent $event) {
                /** @var Entry $entry */
                $entry = $event->sender;
                $event->html .= $this->sidebarHtml($entry);
            });
        }
    }

    public function getExtractor(): Extractor
    {
        return $this->get('extractor');
    }

    public function getIndex(): Index
    {
        return $this->get('index');
    }

    public function getReport(): Report
    {
        return $this->get('report');
    }

    public function getRedirects(): Redirects
    {
        return $this->get('redirects');
    }

    public function getChecker(): Checker
    {
        return $this->get('checker');
    }

    public function getHosts(): Hosts
    {
        return $this->get('hosts');
    }

    public function getUrls(): Urls
    {
        return $this->get('urls');
    }

    public function getIgnores(): Ignores
    {
        return $this->get('ignores');
    }

    /**
     * The Links panel: the problems of the page as it's edited (settings → panel), for entries in a checked section.
     */
    public function sidebarHtml(Entry $entry): string
    {
        $section = $entry->getSection();
        if (!$this->getSettings()->panel || $entry->getIsRevision() || $section === null || !$this->getSettings()->checksSection($section->handle)) {
            return '';
        }
        $view = Craft::$app->getView();
        $view->registerAssetBundle(LinkCheckerAsset::class);
        return $view->renderTemplate('link-checker/_panel/panel', [
            'entry' => $entry,
        ] + $this->panelVariables($entry), View::TEMPLATE_MODE_CP);
    }

    /**
     * The list of the Links panel, also to refresh it after an autosave.
     */
    public function panelListHtml(Entry $entry): string
    {
        return Craft::$app->getView()->renderTemplate('link-checker/_panel/list', $this->panelVariables($entry), View::TEMPLATE_MODE_CP);
    }

    /**
     * @return array{problems: array<array<string, mixed>>, total: int, elementId: int, siteId: int}
     */
    private function panelVariables(Entry $entry): array
    {
        $result = $this->getExtractor()->extract($entry);
        $problems = $this->getReport()->evaluate($result['links'], $result['anchors'], (int)$entry->getCanonicalId(), (int)$entry->siteId);
        usort($problems, fn(array $a, array $b) => ($a['severity'] === Report::BROKEN ? 0 : 1) <=> ($b['severity'] === Report::BROKEN ? 0 : 1));
        foreach ($problems as &$problem) {
            $problem['reasonLabel'] = Report::reasonLabel((string)$problem['reason']);
            unset($problem['element'], $problem['chain']);
        }
        unset($problem);
        return ['problems' => $problems, 'total' => count($result['links']), 'elementId' => (int)$entry->id, 'siteId' => (int)$entry->siteId];
    }

    /**
     * Reads the links of every page once the plugin is installed.
     */
    protected function afterInstall(): void
    {
        Queue::push(new RebuildJob());
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
