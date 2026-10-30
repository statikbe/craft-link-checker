<?php

namespace statikbe\linkchecker\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

/**
 * The Links panel in the entry sidebar.
 */
class LinkCheckerAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = ['panel.css'];
        $this->js = ['panel.js'];
        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);
        if ($view instanceof View) {
            $view->registerTranslations('link-checker', [
                '{count, plural, =1{# broken link} other{# broken links}}',
                '{count, plural, =1{# warning} other{# warnings}}',
            ]);
        }
    }
}
