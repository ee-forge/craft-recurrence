<?php

namespace eeforge\recurrence\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use DateTimeImmutable;
use eeforge\recurrence\fields\Recurrence;
use eeforge\recurrence\models\Schedule;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Renders the field's "upcoming dates" preview from the unsaved value, using
 * the same expansion code as the front end.
 */
class PreviewController extends Controller
{
    /** Most dates the preview lists, however often "Show more" is clicked. */
    private const MAX_LIMIT = 100;

    /**
     * Restricts the controller to CP, POST, JSON requests: it's only ever
     * called by the field input (input.js) as the editor changes the rule.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        return true;
    }

    /**
     * Renders the preview for an unsaved value. Called by input.js on every
     * change, and again with a higher `limit` when "Show more" is clicked.
     *
     * Posts: `fieldId`, `value` (the field's JSON), optional `limit`.
     * Returns: the summary, validation errors, and the dates list HTML.
     */
    public function actionRender(): Response
    {
        // Resolve the field, so normalizing and validating use its settings.
        $field = Craft::$app->getFields()->getFieldById((int)$this->request->getRequiredBodyParam('fieldId'));

        if (!$field instanceof Recurrence) {
            throw new BadRequestHttpException('Invalid field.');
        }

        // Turn the posted JSON into a Schedule, as a save would. An empty or
        // unparseable value just clears the preview.
        $raw = $this->request->getBodyParam('value');
        $data = is_string($raw) ? Json::decodeIfJson($raw) : $raw;
        $schedule = is_array($data) ? $field->normalizeValue($data, null) : null;

        if (!$schedule instanceof Schedule) {
            return $this->asJson(['html' => '', 'summary' => '', 'errors' => []]);
        }

        // How many dates to list, capped so "Show more" can't ask for the world.
        $limit = min(self::MAX_LIMIT, max(1, (int)$this->request->getBodyParam('limit', Recurrence::PREVIEW_LIMIT)));

        // Upcoming dates, cancelled ones included so editors see them; one
        // extra tells whether "Show more" has anything to show.
        $occurrences = $schedule->upcoming($limit + 1, true);
        $hasMore = count($occurrences) > $limit && $limit < self::MAX_LIMIT;
        $occurrences = array_slice($occurrences, 0, $limit);
        $past = false;

        if (!$occurrences && ($first = $schedule->getStartLocal())) {
            // The whole series is over; show its last dates instead.
            $occurrences = $schedule->between($first, new DateTimeImmutable(), $limit, true, latest: true);
            $past = true;
        }

        // Summary ("Every week on Wednesday…"), errors, and the list itself.
        return $this->asJson([
            'summary' => $schedule->getSummary(),
            'errors' => $field->validationErrors($schedule),
            'html' => $this->getView()->renderTemplate('recurrence/_field/preview.twig', [
                'schedule' => $schedule,
                'occurrences' => $occurrences,
                'past' => $past,
                'hasMore' => $hasMore,
                'static' => false,
            ]),
        ]);
    }
}
