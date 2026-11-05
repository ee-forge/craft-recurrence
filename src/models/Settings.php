<?php

namespace eeforge\recurrence\models;

use craft\base\Model;
use craft\helpers\StringHelper;
use eeforge\recurrence\helpers\IcsDetails;

/**
 * Plugin settings, edited at Settings → Plugins → Recurrence and stored in
 * project config. Any of them can be overridden in `config/recurrence.php`.
 *
 * The first three decide which dates the occurrences table holds, and
 * changing them rebuilds it (Plugin::handleChangedSettings()); the rest only
 * affect ICS output.
 */
class Settings extends Model
{
    /** Months ahead that open-ended series are stored in the occurrences table. */
    public int $horizonMonths = 18;

    /** Months of past occurrences to keep in the occurrences table. */
    public int $pastMonths = 12;

    /**
     * Hard cap on occurrences per element.
     *
     * These three are the only defaults for the limits: Schedule has none of
     * its own and gets the values through Recurrence::configure().
     */
    public int $maxOccurrences = 500;

    /**
     * Where the ICS DESCRIPTION comes from: blank for none, a field UID, or
     * `template` for `$icsDescriptionTemplate`.
     */
    public string $icsDescriptionSource = IcsDetails::SOURCE_NONE;

    /** Object template for the ICS DESCRIPTION, rendered against the entry. */
    public string $icsDescriptionTemplate = '';

    /**
     * Where the ICS LOCATION comes from: blank for none, a field UID (plain
     * text, Addresses or Entries), or `template` for `$icsLocationTemplate`.
     */
    public string $icsLocationSource = IcsDetails::SOURCE_NONE;

    /** Object template for the ICS LOCATION, rendered against the entry. */
    public string $icsLocationTemplate = '';

    /** Feed calendar name; `{section}` is replaced with the section name. */
    public string $icsFeedName = '{section}';

    /** Validation for the CP settings form. Source settings are blank, `template`, or a field UID. */
    protected function defineRules(): array
    {
        return [
            [['horizonMonths', 'pastMonths', 'maxOccurrences'], 'integer', 'min' => 1],
            [['maxOccurrences'], 'integer', 'max' => 10000],
            [['icsDescriptionTemplate', 'icsLocationTemplate', 'icsFeedName'], 'string'],
            [['icsDescriptionSource', 'icsLocationSource'], 'match', 'pattern' => '/^(|template|' . StringHelper::UUID_PATTERN . ')$/'],
        ];
    }
}
