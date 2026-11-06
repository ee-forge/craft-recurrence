<?php

namespace eeforge\recurrence\services;

use DateTimeImmutable;
use DateTimeInterface;
use eeforge\recurrence\models\Occurrence;
use eeforge\recurrence\models\Schedule;
use RRule\RRule;
use RRule\RSet;

/**
 * Turns a Schedule into concrete occurrences.
 *
 * Pure PHP with no Craft dependency. The site, the CP preview, the occurrences table
 * and the ICS feed all go through here, so they can't disagree about which
 * dates exist.
 *
 * Expansion happens in the schedule's own timezone and keeps wall-clock time,
 * so DST changes don't shift an event's local start time.
 *
 * Two keys identify each occurrence (see Occurrence): `key`, from its
 * *original* local date, under which overrides are stored; and `urlKey`, from
 * its *actual* date after any reschedule, used in its URL. Both are the bare
 * date unless two occurrences share the day, when `-HHMM` is appended.
 *
 * Templates and services don't call this directly; they go through Schedule
 * (between(), upcoming(), occurrence()…), Ics and the field's validation.
 */
class Expander
{
    /**
     * Occurrences overlapping [$from, $to), ordered by start, with overrides
     * applied and URL keys set. The core expansion behind Schedule::between(),
     * upcoming() and previous(), and so behind the occurrences table and the CP preview.
     *
     * @param int|null $limit At most this many (and never more than the schedule's maxOccurrences)
     * @param bool $includeCancelled Whether cancelled dates are included (left out by default)
     * @param bool $latest Keep the last dates in the range rather than the first, when there are more than the limit
     * @return Occurrence[]
     */
    public static function between(
        Schedule $schedule,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?int $limit = null,
        bool $includeCancelled = false,
        bool $latest = false,
    ): array {
        $first = $schedule->getStartLocal();
        if (!$first) {
            return [];
        }

        // Work in the schedule's own zone, so local days and times are its own.
        $tz = $schedule->getTimeZone();
        $from = DateTimeImmutable::createFromInterface($from)->setTimezone($tz);
        $to = DateTimeImmutable::createFromInterface($to)->setTimezone($tz);

        // An occurrence that started before $from can still be ongoing, so
        // widen the window by the event's length. Whole local days keep
        // occurrence keys stable, because keys depend on how many
        // occurrences share a day.
        $span = $first->diff($schedule->getEndLocal());
        $windowStart = $from->sub($span)->setTime(0, 0);
        $windowEnd = $to->setTime(23, 59, 59);

        // The expansion is capped from the window's start. The window reaches
        // back before $from for dates still ongoing, so leave room for those.
        // For the last dates ($latest), a cap from the start would cut them
        // off; expand the whole window (bounded by its dates, not a count)
        // and trim from the end below.
        $expansionCap = $latest ? null : $schedule->getMaxOccurrences() + count(self::originals($schedule, $windowStart, $from));

        // Every original date in the window, with its override applied.
        $occurrences = [];
        foreach (self::originals($schedule, $windowStart, $windowEnd, $expansionCap) as $key => $start) {
            $occurrences[$key] = self::build($schedule, $key, $start);
        }

        // Rescheduled occurrences can move into the window from outside it.
        foreach ($schedule->overrides as $key => $override) {
            if (!isset($occurrences[$key]) && !empty($override['start'])) {
                $moved = self::findOriginal($schedule, $key);
                if ($moved) {
                    $occurrences[$key] = $moved;
                }
            }
        }

        // URL keys come from actual dates, so set them once all are known.
        self::assignUrlKeys($schedule, $occurrences, $windowStart, $windowEnd);

        // Trim the widened window back to the requested range (overlap, not
        // containment), dropping cancelled dates if asked.
        $result = array_filter($occurrences, fn(Occurrence $o) =>
            $o->end >= $from
            && $o->start < $to
            && ($includeCancelled || !$o->cancelled));

        // In start order; the key breaks ties so the order is stable.
        usort($result, fn(Occurrence $a, Occurrence $b) => [$a->start, $a->key] <=> [$b->start, $b->key]);

        // Never more than the schedule's hard cap, whatever the caller asked;
        // the first dates, or the last ones when $latest.
        $cap = $schedule->getMaxOccurrences();
        $limit = max(0, $limit !== null ? min($limit, $cap) : $cap);
        if ($latest) {
            return $limit ? array_slice($result, -$limit) : [];
        }
        return array_slice($result, 0, $limit);
    }

    /**
     * The occurrence with the given (original) key, or null if the key was
     * never part of the series (or its date was excluded). Used by Ics, which
     * works in original keys (RECURRENCE-IDs).
     */
    public static function find(Schedule $schedule, string $key): ?Occurrence
    {
        $occurrence = self::findOriginal($schedule, $key);
        if ($occurrence) {
            $occurrence->urlKey = self::urlKey($occurrence, self::startingOn($schedule, $occurrence->start));
        }
        return $occurrence;
    }

    /**
     * The occurrence a dated URL points to, by its URL key (its actual date),
     * or null. Behind Schedule::occurrence(), and so the dated-page controller
     * and `craft.recurrence.occurrence()`.
     */
    public static function findByUrlKey(Schedule $schedule, string $key): ?Occurrence
    {
        // Accept only `yyyy-mm-dd` or `yyyy-mm-dd-HHMM`, and only real dates
        // (createFromFormat would roll 2026-02-31 over to March).
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:-\d{4})?$/', $key, $m)) {
            return null;
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $m[1], $schedule->getTimeZone());
        if (!$day || $day->format('Y-m-d') !== $m[1]) {
            return null;
        }

        // Everything that actually starts that day, rescheduled dates
        // included, then the one whose key matches.
        $sameDay = self::startingOn($schedule, $day);
        foreach ($sameDay as $occurrence) {
            if (self::urlKey($occurrence, $sameDay) === $key) {
                $occurrence->urlKey = $key;
                return $occurrence;
            }
        }

        return null;
    }

    /**
     * Original keys of rescheduled occurrences that land at the exact start of
     * another occurrence, which would leave the two without distinct URLs.
     * The field's validation rejects schedules with any.
     *
     * @return string[]
     */
    public static function collisions(Schedule $schedule): array
    {
        $keys = [];
        foreach ($schedule->overrides as $key => $override) {
            if (empty($override['start']) || !($moved = self::findOriginal($schedule, $key))) {
                continue;
            }
            foreach (self::startingOn($schedule, $moved->start) as $other) {
                if ($other->key !== $key && $other->start == $moved->start) {
                    $keys[] = $key;
                    break;
                }
            }
        }
        return $keys;
    }

    /**
     * find() without the URL key, for callers that set it themselves (or don't
     * need it). Expands only the key's own day, so it's cheap enough to call
     * per override.
     */
    private static function findOriginal(Schedule $schedule, string $key): ?Occurrence
    {
        // Same validation as findByUrlKey(): the shape, then a real date.
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:-\d{4})?$/', $key, $m)) {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $m[1], $schedule->getTimeZone());
        if (!$day || $day->format('Y-m-d') !== $m[1]) {
            return null;
        }

        $originals = self::originals($schedule, $day, $day->setTime(23, 59, 59));
        return isset($originals[$key]) ? self::build($schedule, $key, $originals[$key]) : null;
    }

    /**
     * Original (un-overridden) local start times in the window, keyed by
     * occurrence key, with excluded dates removed. The raw rule expansion
     * every other method builds on; Ics also uses it to list excluded dates.
     *
     * @param int|null $cap At most this many dates, from $from on; null for every date in the window
     * @return array<string, DateTimeImmutable>
     */
    public static function originals(Schedule $schedule, DateTimeImmutable $from, DateTimeImmutable $to, ?int $cap = null): array
    {
        $first = $schedule->getStartLocal();
        if (!$first || $to < $first) {
            return [];
        }

        // The rule (if any) via php-rrule, anchored at the first start.
        $set = new RSet();
        if ($parts = $schedule->getRRuleParts()) {
            $set->addRRule(new RRule($parts + ['DTSTART' => $first]));
        }
        // RFC 5545 counts DTSTART as the first occurrence even when it doesn't
        // match the rule (e.g. a Thursday start on a Mon/Wed rule). RSet
        // de-duplicates, so an RDATE equal to the start isn't listed twice.
        $set->addDate($first);

        // Extra dates ("More dates") only apply to repeating schedules.
        if ($schedule->freq !== Schedule::FREQ_NONE) {
            foreach ($schedule->rdates as $rdate) {
                if ($dt = $schedule->parseLocal($rdate, false)) {
                    $set->addDate($dt);
                }
            }
        }

        // Expand within the window, capped, back in the schedule's zone.
        $starts = [];
        foreach ($set->getOccurrencesBetween($from, $to, $cap) as $dt) {
            $starts[] = DateTimeImmutable::createFromInterface($dt)->setTimezone($schedule->getTimeZone());
        }

        // Key by local date. When two occurrences share a day, every
        // occurrence on that day gets the time appended.
        $perDay = [];
        foreach ($starts as $start) {
            $perDay[$start->format('Y-m-d')][] = $start;
        }

        $keyed = [];
        foreach ($perDay as $date => $dayStarts) {
            foreach ($dayStarts as $start) {
                $key = count($dayStarts) > 1 ? $date . '-' . $start->format('Hi') : $date;
                // Skipped dates are stored as a bare date or a full key.
                if (in_array($date, $schedule->exdates, true) || in_array($key, $schedule->exdates, true)) {
                    continue;
                }
                $keyed[$key] = $start;
            }
        }

        return $keyed;
    }

    /**
     * Sets each occurrence's URL key from its actual local date. Days inside
     * the expanded window are complete (every original on them plus every
     * rescheduled occurrence); any other day is looked up on its own.
     *
     * @param Occurrence[] $occurrences
     */
    private static function assignUrlKeys(Schedule $schedule, array $occurrences, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): void
    {
        // Group by actual start day; a day's count decides whether keys need a time.
        $byDay = [];
        foreach ($occurrences as $occurrence) {
            $byDay[$occurrence->start->format('Y-m-d')][] = $occurrence;
        }
        foreach ($byDay as $date => $sameDay) {
            // A day outside the window (a rescheduling that moved in) may have
            // other occurrences we haven't expanded, so look that day up fully.
            $complete = $date >= $windowStart->format('Y-m-d') && $date <= $windowEnd->format('Y-m-d');
            $all = $complete ? $sameDay : self::startingOn($schedule, $sameDay[0]->start);
            foreach ($sameDay as $occurrence) {
                $occurrence->urlKey = self::urlKey($occurrence, $all);
            }
        }
    }

    /**
     * `Y-m-d` of the actual start, plus `-Hi` when other occurrences start
     * the same day. Used by find(), findByUrlKey() and assignUrlKeys().
     *
     * @param Occurrence[] $sameDay All occurrences starting that day
     */
    private static function urlKey(Occurrence $occurrence, array $sameDay): string
    {
        $date = $occurrence->start->format('Y-m-d');
        return count($sameDay) > 1 ? $date . '-' . $occurrence->start->format('Hi') : $date;
    }

    /**
     * Every occurrence (after overrides) whose actual start falls on the
     * given local day: the day's own originals, plus any rescheduled into it.
     * Used to work out URL keys and collisions for a single day.
     *
     * @return Occurrence[]
     */
    private static function startingOn(Schedule $schedule, DateTimeImmutable $day): array
    {
        $day = $day->setTimezone($schedule->getTimeZone())->setTime(0, 0);
        $occurrences = [];
        foreach (self::originals($schedule, $day, $day->setTime(23, 59, 59)) as $key => $start) {
            $occurrences[$key] = self::build($schedule, $key, $start);
        }
        foreach ($schedule->overrides as $key => $override) {
            if (!isset($occurrences[$key]) && !empty($override['start']) && ($moved = self::findOriginal($schedule, $key))) {
                $occurrences[$key] = $moved;
            }
        }
        $date = $day->format('Y-m-d');
        return array_values(array_filter($occurrences, fn(Occurrence $o) => $o->start->format('Y-m-d') === $date));
    }

    /**
     * One occurrence from its original start, with its override (if any)
     * applied: a new start and end, cancellation, and note. The URL key is set
     * afterward by the caller.
     */
    private static function build(Schedule $schedule, string $key, DateTimeImmutable $originalStart): Occurrence
    {
        // By default, the date keeps the series' length.
        $end = $schedule->endFor($originalStart);
        $start = $originalStart;
        $override = $schedule->overrides[$key] ?? [];
        $rescheduled = false;

        if (!empty($override['start']) && ($newStart = $schedule->parseLocal($override['start'], false))) {
            // An override's own end is measured from its typed start, like the series' length.
            $length = Schedule::wallClockLength($override['start'], $override['end'] ?? null);
            $start = $newStart;
            $end = $length ? $schedule->applyLength($newStart, $length) : $schedule->endFor($newStart);
            // Flag it only if something actually moved, not merely re-saved.
            $rescheduled = $start != $originalStart || $end != $schedule->endFor($originalStart);
        }

        return new Occurrence(
            start: $start,
            end: $end,
            originalStart: $originalStart,
            key: $key,
            allDay: $schedule->allDay,
            cancelled: !empty($override['cancelled']),
            note: $override['note'] ?? null,
            rescheduled: $rescheduled,
            element: $schedule->element,
        );
    }
}
