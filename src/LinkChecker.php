<?php

namespace statikbe\linkchecker;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\helpers\Queue;
use craft\services\Dashboard;
use craft\web\View;
use statikbe\linkchecker\jobs\RebuildJob;
use statikbe\linkchecker\models\Settings;
use statikbe\linkchecker\services\Extractor;
use statikbe\linkchecker\services\Index;
use statikbe\linkchecker\services\Redirects;
use statikbe\linkchecker\services\Report;
use statikbe\linkchecker\widgets\BrokenLinks;
use yii\base\Event;

/**
 * Link Checker: finds the broken links in the content of entries and lists the pages that contain them.
 *
 * @method Settings getSettings()
 */
class LinkChecker extends Plugin
{
    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => [
                'extractor' => Extractor::class,
                'index' => Index::class,
                'report' => Report::class,
                'redirects' => Redirects::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // A saved page (or a block on it) is read again at the end of the request
        Event::on(Entry::class, Element::EVENT_AFTER_SAVE, function(ModelEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            $this->getIndex()->entrySaved($entry);
        });
        Event::on(Entry::class, Element::EVENT_AFTER_RESTORE, function(Event $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            $this->getIndex()->entrySaved($entry);
        });
        Event::on(Entry::class, Element::EVENT_AFTER_DELETE, function(Event $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            $this->getIndex()->forget($entry);
        });

        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = BrokenLinks::class;
        });

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, function(RegisterTemplateRootsEvent $event) {
                $event->roots['link-checker'] = __DIR__ . '/templates';
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
