<?php

namespace eeforge\recurrence\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use eeforge\recurrence\Plugin;

/**
 * Rewrites every entry's rows in the occurrences table in the background.
 *
 * Queued by Plugin::handleChangedSettings() when a setting that shapes the
 * occurrences table (horizon, past months kept, maximum dates) changes, whether from a CP
 * save or `craft up`. Same work as `craft recurrence/occurrences/rebuild`.
 */
class RebuildOccurrences extends BaseJob
{
    /**
     * Rebuilds every entry whose layout has a Recurrence field, reporting
     * progress to the queue as it goes.
     */
    public function execute($queue): void
    {
        Plugin::getInstance()->getOccurrences()->rebuildAll(
            Entry::class,
            [],
            function($element, int $done, int $total) use ($queue) {
                $this->setProgress($queue, $done / max(1, $total));
            },
        );
    }

    /** Label shown in the CP's queue manager. */
    protected function defaultDescription(): ?string
    {
        return Craft::t('recurrence', 'Rebuilding event dates');
    }
}
