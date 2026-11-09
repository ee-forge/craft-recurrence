<?php

namespace eeforge\recurrence\variables;

use craft\base\ElementInterface;
use craft\helpers\UrlHelper;
use eeforge\recurrence\models\Occurrence;
use eeforge\recurrence\models\Schedule;
use eeforge\recurrence\Plugin;
use eeforge\recurrence\queries\OccurrenceQuery;

/**
 * `craft.recurrence` in templates, registered by Plugin::init(). A thin layer
 * over the query, Schedule and URL helpers so templates needn't know the
 * field's handle or the plugin's routes.
 */
class RecurrenceVariable
{
    /**
     * Dates across entries, from the occurrences table:
     * `craft.recurrence.occurrences().section('events').from(now).limit(12)`.
     *
     * Each criteria key is called as a query method, so
     * `occurrences({section: 'events', limit: 12})` equals the chained form.
     *
     * @param array $criteria query settings (`from`, `to`, `limit`, …) and/or element criteria (`section`, …)
     */
    public function occurrences(array $criteria = []): OccurrenceQuery
    {
        $query = new OccurrenceQuery();
        foreach ($criteria as $name => $value) {
            $query->$name($value);
        }
        return $query;
    }

    /**
     * One occurrence of an element by its URL key (its actual date), e.g.
     * `2026-11-12`, or `2026-11-12-1900` when two dates share the day. Null
     * when no date has that key. For templates on a custom route that load
     * the date themselves (see the README).
     */
    public function occurrence(ElementInterface $element, string $key): ?Occurrence
    {
        return $this->schedule($element)?->occurrence($key);
    }

    /** The element's Recurrence field value, whatever the field's handle; null if it has none. */
    public function schedule(ElementInterface $element): ?Schedule
    {
        $field = Plugin::getInstance()->recurrenceField($element);
        $value = $field ? $element->getFieldValue($field->handle) : null;
        return $value instanceof Schedule ? $value : null;
    }

    /** Subscription URL for every entry with a schedule in a section, served by IcsController::actionFeed(). */
    public function feedUrl(string $sectionHandle): string
    {
        return UrlHelper::siteUrl("recurrence/feed/$sectionHandle.ics");
    }

    /**
     * Download URL for one element's calendar file, `recurrence/ics/{uid}.ics`,
     * served by IcsController::actionElement(); null if it has no page or
     * schedule (the controller would 404 either way).
     */
    public function icsUrl(ElementInterface $element): ?string
    {
        if (!$element->uri || !Plugin::getInstance()->recurrenceField($element)) {
            return null;
        }
        return UrlHelper::siteUrl("recurrence/ics/$element->uid.ics", null, null, $element->siteId);
    }
}
