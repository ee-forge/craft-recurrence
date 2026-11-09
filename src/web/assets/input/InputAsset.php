<?php

namespace eeforge\recurrence\web\assets\input;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\assets\timepicker\TimepickerAsset;
use craft\web\View;

/**
 * The field input's JS and CSS. Plain files, no build step, so the plugin
 * doesn't depend on the host site's front-end tooling.
 *
 * Registered by Recurrence::inputHtml() whenever the field is shown in the CP.
 */
class InputAsset extends AssetBundle
{
    /** Points the bundle at `dist/` and declares the CP assets it builds on. */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        // CpAsset brings the jQuery UI datepicker; the timepicker is separate.
        $this->depends = [CpAsset::class, TimepickerAsset::class];
        $this->js = ['input.js'];
        $this->css = ['input.css'];
        parent::init();
    }

    /**
     * Publishes the files, then registers the strings the JS renders itself so
     * they're translatable like the Twig parts of the input.
     */
    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        // Strings the JS renders itself, available there via Craft.t('recurrence', …).
        if ($view instanceof View) {
            $view->registerTranslations('recurrence', [
                'day', 'days', 'week', 'weeks', 'month', 'months', 'year', 'years',
                'first', 'second', 'third', 'fourth', 'last',
                'on day {n}', 'on the {nth} {day}', 'on the last {day}', 'at {time}',
                'More dates', 'Extra dates', 'Date', 'Time', 'Remove',
                'Note for {date}', 'Shown with this date. Leave blank to remove it.', 'New start date', 'New start time', 'New end date', 'New end time',
                'Apply', 'Close', 'Changed dates', 'cancelled', 'moved to {date}', 'Undo changes',
                'Mark as cancelled', 'Restore date', 'Reschedule', 'Add note', 'Edit note', 'Remove from series',
            ]);
        }
    }
}
