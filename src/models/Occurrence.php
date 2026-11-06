<?php

namespace eeforge\recurrence\models;

use DateTimeImmutable;

/**
 * One concrete date of a schedule.
 *
 * `key` identifies the occurrence by its *original* local date (plus time when
 * a series has two occurrences on the same day). Overrides are stored under
 * it, as RFC 5545 RECURRENCE-IDs are.
 *
 * `urlKey` is the same shape built from the *actual* date, so a rescheduled
 * occurrence's URL follows its new date.
 *
 * Built by the Expander for on-the-fly expansion, and by OccurrenceQuery from
 * occurrences table rows; templates get these from `entry.field.upcoming()`,
 * `craft.recurrence.occurrences()` and the `occurrence` variable on dated pages.
 */
class Occurrence
{
    /**
     * @param DateTimeImmutable $start Actual start, after any reschedule
     * @param DateTimeImmutable $end Actual end
     * @param DateTimeImmutable $originalStart Start before any reschedule
     * @param string $key Original key; overrides are stored under it
     * @param bool $cancelled Won't happen; left out of listings unless they include cancelled dates
     * @param string|null $note Editor's note for this date only
     * @param bool $rescheduled Whether an override changed its start or end
     * @param object|null $element The owning element (entry)
     * @param string|null $urlKey Key used in its URL; defaults to $key
     */
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public DateTimeImmutable $originalStart,
        public string $key,
        public bool $allDay = false,
        public bool $cancelled = false,
        public ?string $note = null,
        public bool $rescheduled = false,
        public ?object $element = null,
        public ?string $urlKey = null,
    ) {
        $this->urlKey ??= $key;
    }

    /** Alias of `element`, which reads better in entry templates. */
    public function getEntry(): ?object
    {
        return $this->element;
    }

    /** Whether it has ended, as of `$now` (default: the current time). */
    public function getIsPast(?DateTimeImmutable $now = null): bool
    {
        return $this->end < ($now ?? new DateTimeImmutable());
    }

    /** Whether it has started and not yet ended, as of `$now` (default: the current time). */
    public function getIsOngoing(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();
        return $this->start <= $now && $this->end >= $now;
    }

    /** Whether it ends on a later day than it starts, in the times' own zone. Templates use it to show a date range. */
    public function getIsMultiDay(): bool
    {
        return $this->start->format('Y-m-d') !== $this->end->format('Y-m-d');
    }

    /**
     * The occurrence's own URL: the element URL plus `/{urlKey}`, the shape
     * the README's site route serves through OccurrenceController. Null when
     * the element has no URL. Sites using another route shape build links
     * themselves from `urlKey`.
     */
    public function getUrl(): ?string
    {
        if (!$this->element || !method_exists($this->element, 'getUrl')) {
            return null;
        }

        $url = $this->element->getUrl();
        if (!$url) {
            return null;
        }

        // Insert the key before any query string (e.g. a preview token).
        $query = '';
        if (($pos = strpos($url, '?')) !== false) {
            $query = substr($url, $pos);
            $url = substr($url, 0, $pos);
        }

        // Match the site's trailing-slash setting. Craft may not be loaded in unit tests.
        $slash = class_exists(\Craft::class, false) && isset(\Craft::$app)
            && \Craft::$app->getConfig()->getGeneral()->addTrailingSlashesToUrls ? '/' : '';
        return rtrim($url, '/') . '/' . $this->urlKey . $slash . $query;
    }
}
