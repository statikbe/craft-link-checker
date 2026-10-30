<?php

namespace statikbe\linkchecker\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use statikbe\linkchecker\LinkChecker;
use statikbe\linkchecker\services\Report;
use statikbe\linkchecker\utilities\LinkCheckerUtility;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The Link Checker utility's actions: the CSV export, and ignoring a link (also from the Links panel) or ignoring it no more.
 */
class UtilityController extends Controller
{
    public function actionExport(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('utility:link-checker');

        $plugin = LinkChecker::getInstance();
        $filters = [
            'severity' => (string)$this->request->getQueryParam('severity', 'all'),
            'siteId' => (int)$this->request->getQueryParam('siteId', 0),
            'q' => trim((string)$this->request->getQueryParam('q', '')),
        ];
        $problems = LinkCheckerUtility::filtered($plugin->getReport()->cachedProblems(), $filters);
        $sites = Craft::$app->getSites();

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Page', 'Site', 'Edit URL', 'Location', 'Link', 'Target', 'Severity', 'Reason'], escape: '');
        $editUrls = $plugin->getReport()->editUrls($problems);
        foreach ($problems as $problem) {
            $key = $problem['sourceId'] . '-' . $problem['siteId'];
            fputcsv($handle, array_map([self::class, 'csvCell'], [
                $problem['sourceTitle'],
                $sites->getSiteById((int)$problem['siteId'], true)?->getName() ?? '',
                $editUrls[$key],
                $problem['location'],
                $problem['label'] ?? '',
                $problem['targetTitle'] ?? $problem['url'],
                $problem['severity'],
                Report::reasonLabel((string)$problem['reason']),
            ]), escape: '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $this->response->sendContentAsFile($csv, 'broken-links-' . date('Y-m-d') . '.csv', ['mimeType' => 'text/csv']);
    }

    /**
     * A CSV cell that a spreadsheet doesn't run as a formula: text starting with = + - @ or a tab or return gets a quote.
     */
    public static function csvCell(mixed $value): string
    {
        $value = (string)$value;
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    /**
     * Ignores a link everywhere. From the utility, or from the Links panel: only a link that's on the entry the user edits
     * (and may save).
     */
    public function actionIgnore(): ?Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $user = Craft::$app->getUser()->getIdentity();
        $url = (string)$this->request->getRequiredBodyParam('url');
        $elementId = (int)$this->request->getBodyParam('elementId');
        $allowed = $user?->can('utility:link-checker');
        if (!$allowed && $elementId) {
            $entry = Entry::find()
                ->id($elementId)
                ->siteId((int)$this->request->getRequiredBodyParam('siteId'))
                ->drafts(null)
                ->provisionalDrafts(null)
                ->status(null)
                ->one();
            $allowed = $entry !== null
                && Craft::$app->getElements()->canSave($entry)
                && in_array(trim($url), array_map(fn(array $link) => trim((string)$link['url']), LinkChecker::getInstance()->getExtractor()->extract($entry)['links']), true);
        }
        if (!$allowed) {
            throw new ForbiddenHttpException('Not allowed to ignore links.');
        }
        LinkChecker::getInstance()->getIgnores()->add($url, $user?->id);

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess();
        }
        return $this->asSuccess(Craft::t('link-checker', 'The link is ignored.'));
    }

    public function actionUnignore(): ?Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('utility:link-checker');
        LinkChecker::getInstance()->getIgnores()->remove((int)$this->request->getRequiredBodyParam('id'));
        return $this->asSuccess(Craft::t('link-checker', 'The link is no longer ignored.'));
    }
}
