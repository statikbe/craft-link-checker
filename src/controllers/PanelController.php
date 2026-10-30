<?php

namespace statikbe\linkchecker\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use statikbe\linkchecker\LinkChecker;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The Links panel reloads its list here after an autosave, so it shows the links as edited.
 */
class PanelController extends Controller
{
    public function actionList(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $entry = Entry::find()
            ->id((int)$this->request->getRequiredBodyParam('elementId'))
            ->siteId((int)$this->request->getRequiredBodyParam('siteId'))
            ->drafts(null)
            ->provisionalDrafts(null)
            ->status(null)
            ->one();
        if ($entry === null || !Craft::$app->getElements()->canView($entry)) {
            throw new ForbiddenHttpException('Not allowed to view this entry.');
        }

        return $this->asJson(['html' => LinkChecker::getInstance()->panelListHtml($entry)]);
    }
}
