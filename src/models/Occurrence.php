<?php

namespace eeforge\recurrence\models;

use craft\base\ElementInterface;
use craft\helpers\UrlHelper;
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
     * @param DateTimeImmutable $start actual start, after any reschedule
     * @param DateTimeImmutable $end actual end
     * @param DateTimeImmutable $originalStart start before any reschedule
     * @param string $key original key; overrides are stored under it
     * @param bool $cancelled won't happen; left out of listings unless they include cancelled dates
     * @param string|null $note editor's note for this date only
     * @param bool $rescheduled whether an override changed its start or end
     * @param ElementInterface|null $element the owning element (entry)
     * @param string|null $urlKey key used in its URL; defaults to $key
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
        public ?ElementInterface $element = null,
        public ?string $urlKey = null,
    ) {
        $this->urlKey ??= $key;
    }

    /** Alias of `element`, which reads better in entry templates. */
    public function getEntry(): ?ElementInterface
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
     * The occurrence's own URL: the element's URI plus `/{urlKey}`, the shape
     * the README's site route serves through OccurrenceController. Built like
     * Element::getUrl(), so it follows the site's URL settings (trailing
     * slashes, `index.php?p=`) and keeps preview tokens. Null when the element
     * has no URI. Sites using another route shape build links themselves from
     * `urlKey`.
     */
    public function getUrl(): ?string
    {
        $uri = $this->element?->uri;
        if ($uri === null) {
            return null;
        }

        $path = $this->element->getIsHomepage() ? $this->urlKey : $uri . '/' . $this->urlKey;
        return UrlHelper::siteUrl($path, null, null, $this->element->siteId);
    }
}
