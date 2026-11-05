<?php

namespace eeforge\recurrence\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\web\Controller;
use eeforge\recurrence\helpers\Ics;
use eeforge\recurrence\helpers\IcsDetails;
use eeforge\recurrence\models\Schedule;
use eeforge\recurrence\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * One entry's calendar file, at `recurrence/ics/{uid}.ics` or at any route a
 * site points here with a `uri` param (e.g. `events/{slug}.ics`), and
 * `recurrence/feed/{section}.ics` as a subscribable feed of a whole section.
 * The two plugin routes are registered by Plugin::_siteUrlRules(); links
 * come from `craft.recurrence.icsUrl()` and `.feedUrl()`.
 *
 * The calendar text itself is built by helpers\Ics; this controller finds
 * the entries, resolves each one's description and location from the plugin
 * settings, and sends the response.
 */
class IcsController extends Controller
{
    protected array|int|bool $allowAnonymous = true;

    /**
     * One entry's calendar file, downloaded as an attachment. 404s unless the
     * entry is live, has a page, and has a schedule.
     *
     * @param string|null $uid The entry's UID, from the plugin's own route
     * @param string|null $uri The entry's URI, from a site route
     * @throws NotFoundHttpException if no such live entry, or it has no page or schedule
     */
    public function actionElement(?string $uid = null, ?string $uri = null): Response
    {
        // Find the entry by whichever param the route supplied. Both lookups
        // return live entries only, so disabled or pending ones 404.
        $site = Craft::$app->getSites()->getCurrentSite();

        $element = match (true) {
            $uid !== null => Entry::find()->uid($uid)->siteId($site->id)->one(),
            $uri !== null => Craft::$app->getElements()->getElementByUri($uri, $site->id, true),
            default => null,
        };

        // Only entries with a page get a calendar file, which keeps
        // URL-less entries (settings singles, etc.) from being exposed.
        if (!$element || !$element->getRoute()) {
            throw new NotFoundHttpException('Calendar not found.');
        }

        // Build the calendar; an entry without a schedule has nothing to add.
        $ics = new Ics((string)$element->title);
        if (!$this->_addElement($ics, $element)) {
            throw new NotFoundHttpException('Calendar not found.');
        }

        return $this->_send($ics, ($element->slug ?: 'event') . '.ics', false);
    }

    /**
     * A section's live entries as one calendar, served inline so calendar
     * apps can subscribe to the URL and refresh it.
     *
     * @param string $section The section handle from the route
     * @throws NotFoundHttpException if the section doesn't exist or none of its entries has a schedule
     */
    public function actionFeed(string $section): Response
    {
        $sectionModel = Craft::$app->getEntries()->getSectionByHandle($section);
        if (!$sectionModel) {
            throw new NotFoundHttpException('Calendar not found.');
        }

        // The calendar name from the `icsFeedName` setting, e.g. "New Harmony Events".
        $name = str_replace('{section}', Craft::t('site', $sectionModel->name), Plugin::getInstance()->getSettings()->icsFeedName);
        $ics = new Ics($name);
        $found = false;

        // Every live entry; those without a schedule are skipped by addElement().
        foreach (Entry::find()->section($section)->all() as $entry) {
            $found = $this->_addElement($ics, $entry) || $found;
        }

        if (!$found) {
            throw new NotFoundHttpException('Calendar not found.');
        }

        return $this->_send($ics, "$section.ics", true);
    }

    /**
     * Adds an element's schedule to the calendar, with its description and
     * location resolved from the plugin settings (a field, a template, or
     * none; see IcsDetails). Used by both actions.
     *
     * @return bool Whether the element had a schedule to add
     */
    private function _addElement(Ics $ics, ElementInterface $element): bool
    {
        $field = Plugin::getInstance()->recurrenceField($element);
        $schedule = $field ? $element->getFieldValue($field->handle) : null;

        if (!$schedule instanceof Schedule) {
            return false;
        }

        $settings = Plugin::getInstance()->getSettings();
        $description = IcsDetails::resolve($element, IcsDetails::DESCRIPTION, $settings->icsDescriptionSource, $settings->icsDescriptionTemplate);
        $location = IcsDetails::resolve($element, IcsDetails::LOCATION, $settings->icsLocationSource, $settings->icsLocationTemplate);
        $ics->addSchedule($schedule, [
            // Stable per entry and site host, so re-imports update the event.
            'uid' => $element->uid . '@' . (parse_url(Craft::$app->getSites()->getCurrentSite()->getBaseUrl() ?? '', PHP_URL_HOST) ?: 'craft'),
            'title' => (string)$element->title,
            'url' => $element->getUrl(),
            'updated' => $element->dateUpdated,
            'description' => $description['text'],
            'location' => $location['text'],
            'geo' => $location['geo'],
        ]);

        return true;
    }

    /**
     * Sends the calendar as `text/calendar`: inline for feeds, so calendar apps
     * subscribe rather than download, or as an attachment for single entries.
     */
    private function _send(Ics $ics, string $filename, bool $inline): Response
    {
        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'text/calendar; charset=utf-8');
        $response->getHeaders()->set('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
        $response->data = $ics->render();
        return $response;
    }
}
