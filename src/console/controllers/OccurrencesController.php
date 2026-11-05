<?php

namespace eeforge\recurrence\console\controllers;

use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use eeforge\recurrence\Plugin;
use yii\console\ExitCode;

/**
 * Maintains the occurrences table (`recurrence_occurrences`): one row per date
 * of every saved entry with a Recurrence field, which listings query.
 *
 * - `craft recurrence/occurrences/extend`: the daily cron job.
 * - `craft recurrence/occurrences/rebuild [--section=events]`: rewrites every
 *   entry's rows, for when the table is missing entries or is out of date.
 *
 * Saving an entry, or changing the horizon, past months kept or maximum dates,
 * already rewrites the rows on its own; these commands are for keeping the
 * date window moving and for repairs.
 */
class OccurrencesController extends Controller
{
    /** @var string|null Section handle to limit `rebuild` to */
    public ?string $section = null;

    /** `--section` applies only to `rebuild`; `extend` always covers the whole occurrences table. */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'rebuild') {
            $options[] = 'section';
        }
        return $options;
    }

    /**
     * Rewrites the occurrences table rows of every entry with a Recurrence field.
     *
     * Unlike `extend`, this finds entries through their entry types, so it also
     * writes rows for entries that have none yet, e.g. after a manual DB import,
     * or entries whose dates were all outside an earlier, smaller window.
     */
    public function actionRebuild(): int
    {
        $criteria = $this->section ? ['section' => $this->section] : [];

        // One line per element and site, as each is rewritten.
        $count = Plugin::getInstance()->getOccurrences()->rebuildAll(Entry::class, $criteria, function($entry) {
            $this->stdout("  - $entry->title (site $entry->siteId)\n");
        });

        $this->stdout("Rebuilt $count schedule(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Extends open-ended rules to the horizon and drops rows that fell out of
     * the window. Run daily from cron.
     *
     * Only revisits entries that already have rows in the occurrences table,
     * which keeps the daily run cheap; entries with no rows are picked up by
     * `rebuild` instead.
     */
    public function actionExtend(): int
    {
        $count = Plugin::getInstance()->getOccurrences()->extendAll();
        $this->stdout("Refreshed $count schedule(s).\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
