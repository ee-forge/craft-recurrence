<?php

namespace eeforge\recurrence\controllers;

use Craft;
use craft\web\Controller;
use eeforge\recurrence\models\Occurrence;
use eeforge\recurrence\models\Schedule;
use eeforge\recurrence\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Renders `{entry-uri}/{occurrence-key}` with the entry's own template, plus an
 * `occurrence` variable. The plugin registers no route for it: sites point
 * their own routes here with `uri` and `key` params (see the README), so it
 * never claims URLs a site didn't ask for.
 *
 * The key is the occurrence's actual date, so a rescheduled date's URL
 * follows it and its old URL 404s. Past occurrences still render; keys with
 * no date 404.
 */
class OccurrenceController extends Controller
{
    protected array|int|bool $allowAnonymous = true;

    /**
     * Serves one date's page. Reached through a site route such as
     * `'<uri:events/[^/]+>/<key:…>' => 'recurrence/occurrence/view'`.
     *
     * @param string $uri the entry's URI, captured by the site's route
     * @param string $key the date's URL key, `yyyy-mm-dd` or `yyyy-mm-dd-HHMM`
     * @throws NotFoundHttpException if the entry, the date or the entry's template is missing
     */
    public function actionView(string $uri, string $key): Response
    {
        // The live entry at that URI, and its Recurrence field.
        $site = Craft::$app->getSites()->getCurrentSite();
        $element = Craft::$app->getElements()->getElementByUri($uri, $site->id, true);
        $field = $element ? Plugin::getInstance()->recurrenceField($element) : null;

        if (!$element || !$field) {
            throw new NotFoundHttpException('Page not found.');
        }

        // The date with that key; null for keys that were never in the series.
        $schedule = $element->getFieldValue($field->handle);
        $occurrence = $schedule instanceof Schedule ? $schedule->occurrence($key) : null;

        // Render the way Craft would for the entry's own URL, which needs a
        // plain template route. Sections routed to a custom controller, or
        // with no template for this site, 404 instead.
        $route = $element->getRoute();
        if (!$occurrence instanceof Occurrence || !is_array($route) || ($route[0] ?? null) !== 'templates/render') {
            throw new NotFoundHttpException('Page not found.');
        }

        // A one-off event has a single date; its entry URL is the only page for it.
        if (!$schedule->getIsRecurring()) {
            return $this->redirect($element->getUrl(), 301);
        }

        // Set before rendering, so SEOmatic (which loads its meta as Twig
        // starts), `craft.app.urlManager.matchedElement` and friends see the entry.
        Craft::$app->getUrlManager()->setMatchedElement($element);

        // The section's template, with its usual variables plus `occurrence`.
        $params = $route[1];
        $variables = ($params['variables'] ?? []) + ['occurrence' => $occurrence];

        return $this->renderTemplate($params['template'], $variables);
    }
}
