<?php

namespace statikbe\linkchecker\controllers;

use Craft;
use craft\behaviors\DraftBehavior;
use craft\elements\Entry;
use craft\elements\User;
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
        if ($entry === null || !self::mayRead($entry, Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException('Not allowed to view this entry.');
        }

        return $this->asJson(['html' => LinkChecker::getInstance()->panelListHtml($entry)]);
    }

    /**
     * Whether a user may read the links of an entry as it's edited: they may view it, edit its site, and it's not someone
     * else's provisional draft (their unsaved changes).
     */
    public static function mayRead(Entry $entry, ?User $user): bool
    {
        if ($user === null || !Craft::$app->getElements()->canView($entry, $user)) {
            return false;
        }
        $site = $entry->getSite();
        if (Craft::$app->getIsMultiSite() && !$user->can("editSite:$site->uid")) {
            return false;
        }
        /** @var DraftBehavior|null $draft */
        $draft = $entry->getBehavior('draft');
        return !$entry->isProvisionalDraft || (int)$draft?->creatorId === (int)$user->id;
    }
}
