<?php

namespace eeforge\recurrence\helpers;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use eeforge\recurrence\models\Occurrence;
use eeforge\recurrence\models\Schedule;
use eeforge\recurrence\services\Expander;

/**
 * Writes RFC 5545 calendars.
 *
 * A recurring schedule becomes one VEVENT with RRULE/RDATE/EXDATE, so calendar
 * apps treat it as a series. Rescheduled or annotated occurrences become extra
 * VEVENTs with the same UID and a RECURRENCE-ID; cancelled occurrences become
 * EXDATEs. Times carry a TZID backed by a generated VTIMEZONE.
 *
 * Usage (IcsController): `new Ics($name)`, addSchedule() per entry, then
 * render(). No Craft dependency, so it's unit-tested directly (IcsTest).
 */
class Ics
{
    /** @var array<string, array{0: int, 1: int}> Timezone => [from, to] timestamps to cover */
    private array $timeZones = [];
    /** @var string[] Rendered VEVENT components */
    private array $events = [];

    /**
     * @param string $name Calendar name shown by apps that subscribe (X-WR-CALNAME)
     * @param string $prodId PRODID identifying the generator
     */
    public function __construct(
        private string $name = '',
        private string $prodId = '-//EEForge//Recurrence//EN',
    ) {
    }

    /**
     * Adds an element's schedule to the calendar: a single VEVENT for a
     * one-off, or a series VEVENT plus one override VEVENT per moved or
     * annotated date. Also records the time zone range render() must cover.
     *
     * @param array{uid: string, title: string, url?: ?string, description?: ?string, location?: ?string, geo?: ?array, updated?: ?DateTimeInterface} $meta
     *   `uid` must be stable across downloads so apps update the event rather
     *   than duplicate it; `geo` is `[latitude, longitude]`.
     */
    public function addSchedule(Schedule $schedule, array $meta): void
    {
        $first = $schedule->getStartLocal();
        if (!$first) {
            return;
        }
        // Properties every VEVENT for this entry shares. DTSTAMP is the
        // entry's last update, so apps can tell when it changed.
        $tz = $schedule->tz;
        $stamp = self::utc($meta['updated'] ?? new DateTimeImmutable());
        $base = [
            'UID' => self::text($meta['uid']),
            'DTSTAMP' => $stamp,
            'SUMMARY' => self::text($meta['title']),
        ];
        $extra = array_filter([
            'DESCRIPTION' => isset($meta['description']) && $meta['description'] !== '' ? self::text($meta['description']) : null,
            'LOCATION' => isset($meta['location']) && $meta['location'] !== '' ? self::text($meta['location']) : null,
            'GEO' => self::geo($meta['geo'] ?? null),
        ]);

        // One-off events: write the (possibly overridden) occurrence directly,
        // marking it cancelled rather than leaving it out.
        if (!$schedule->getIsRecurring()) {
            $occ = Expander::find($schedule, $first->format('Y-m-d'));
            if (!$occ) {
                return;
            }

            $lines = $base + $this->_times($schedule, $occ->start, $occ->end) + $extra;
            if ($occ->cancelled) {
                $lines['STATUS'] = 'CANCELLED';
            }

            if (!empty($meta['url'])) {
                $lines['URL'] = $meta['url'];
            }

            $this->events[] = self::component('VEVENT', $lines);
            $this->_coverTimeZone($tz, $occ->start, $occ->end);
            return;
        }

        // Recurring: one VEVENT for the whole series, at its first date's
        // times, with the rule, extra dates and excluded dates.
        $series = $base + $this->_times($schedule, $first, $schedule->getEndLocal()) + $extra;
        if (!empty($meta['url'])) {
            $series['URL'] = $meta['url'];
        }
        $multi = [];
        if ($rrule = $schedule->getRRuleString()) {
            $series['RRULE'] = $rrule;
        }
        if ($schedule->rdates) {
            $multi[] = $this->_dateList('RDATE', $schedule, array_map(
                fn($d) => $schedule->parseLocal($d, false),
                $schedule->rdates,
            ));
        }
        if ($exdates = $this->_excludedStarts($schedule)) {
            $multi[] = $this->_dateList('EXDATE', $schedule, $exdates);
        }
        $this->events[] = self::component('VEVENT', $series, $multi);
        // The VTIMEZONE must cover every date apps will expand, so through the horizon.
        [, $horizon] = $schedule->occurrencesTableWindow();
        $this->_coverTimeZone($tz, $first, $horizon);

        // Moved or annotated occurrences override their instance of the
        // series. Cancelled ones are already EXDATEs, so skipped here.
        foreach ($schedule->overrides as $key => $override) {
            if (!empty($override['cancelled']) || (empty($override['start']) && empty($override['note']))) {
                continue;
            }
            $occurrence = Expander::find($schedule, $key);
            if (!$occurrence) {
                continue;
            }
            $lines = $base + $this->_times($schedule, $occurrence->start, $occurrence->end) + $extra;
            // RECURRENCE-ID names the instance being replaced by its original start.
            $recurrenceId = $this->_dateProperty('RECURRENCE-ID', $schedule, $occurrence->originalStart);
            if ($occurrence->note) {
                // The note leads the description; `\n` is the escaped newline.
                $lines['DESCRIPTION'] = self::text($occurrence->note)
                    . (isset($extra['DESCRIPTION']) ? '\n\n' . $extra['DESCRIPTION'] : '');
            }
            if ($url = $occurrence->getUrl()) {
                $lines['URL'] = $url;
            }
            $this->events[] = self::component('VEVENT', $lines, [$recurrenceId]);
            $this->_coverTimeZone($tz, $occurrence->start, $occurrence->end);
        }
    }

    /**
     * The complete VCALENDAR, CRLF line endings, long lines folded. Called once
     * every schedule has been added.
     */
    public function render(): string
    {
        // Header, then one VTIMEZONE per zone used, then the events.
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . $this->prodId,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];
        if ($this->name !== '') {
            $lines[] = 'X-WR-CALNAME:' . self::text($this->name);
        }
        foreach ($this->timeZones as $tz => [$from, $to]) {
            $lines[] = self::vtimezone($tz, $from, $to);
        }
        foreach ($this->events as $event) {
            $lines[] = $event;
        }
        $lines[] = 'END:VCALENDAR';

        // Fold per line: components are pre-joined, so split them back out first.
        return implode("\r\n", array_map([self::class, 'fold'], explode("\r\n", implode("\r\n", $lines)))) . "\r\n";
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * Original starts of excluded dates and cancelled occurrences, for the
     * series' EXDATE. Calendar apps need the exact start each one removes.
     */
    private function _excludedStarts(Schedule $schedule): array
    {
        // Expand without exclusions to find the starts the exdates remove.
        $all = clone $schedule;
        $all->exdates = [];
        $starts = [];
        foreach ($schedule->exdates as $exdate) {
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', substr($exdate, 0, 10), $schedule->getTimeZone());
            if (!$day) {
                continue;
            }
            foreach (Expander::originals($all, $day, $day->setTime(23, 59, 59)) as $key => $start) {
                // A bare date excludes every start that day; a key just its one.
                if ($key === $exdate || substr($exdate, 0, 10) === $exdate) {
                    $starts[] = $start;
                }
            }
        }
        // Cancelled dates are EXDATEs too: apps have no "cancelled instance" state.
        foreach ($schedule->overrides as $key => $override) {
            if (!empty($override['cancelled']) && ($occ = Expander::find($schedule, $key))) {
                $starts[] = $occ->originalStart;
            }
        }
        usort($starts, fn($a, $b) => $a <=> $b);
        return $starts;
    }

    /**
     * DTSTART/DTEND properties: DATE values when all-day, local time with TZID
     * otherwise. Used for the series and for every override VEVENT.
     */
    private function _times(Schedule $schedule, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($schedule->allDay) {
            return [
                'DTSTART;VALUE=DATE' => $start->format('Ymd'),
                // DTEND is exclusive for all-day events.
                'DTEND;VALUE=DATE' => $end->setTimezone($schedule->getTimeZone())->modify('+1 day')->format('Ymd'),
            ];
        }
        $tz = $schedule->tz;
        return [
            "DTSTART;TZID=$tz" => $start->setTimezone($schedule->getTimeZone())->format('Ymd\THis'),
            "DTEND;TZID=$tz" => $end->setTimezone($schedule->getTimeZone())->format('Ymd\THis'),
        ];
    }

    /** One date property line (e.g. RECURRENCE-ID) in the schedule's date format, DATE or TZID'd local time. */
    private function _dateProperty(string $name, Schedule $schedule, DateTimeImmutable $date): string
    {
        $local = $date->setTimezone($schedule->getTimeZone());
        return $schedule->allDay
            ? "$name;VALUE=DATE:" . $local->format('Ymd')
            : "$name;TZID=$schedule->tz:" . $local->format('Ymd\THis');
    }

    /**
     * A multi-value date property line, e.g. `EXDATE;TZID=…:20261126T190000,…`.
     * Used for RDATE and EXDATE.
     *
     * @param DateTimeImmutable[] $dates
     */
    private function _dateList(string $name, Schedule $schedule, array $dates): string
    {
        $dates = array_filter($dates);
        $format = $schedule->allDay ? 'Ymd' : 'Ymd\THis';
        $values = array_map(fn(DateTimeImmutable $d) => $d->setTimezone($schedule->getTimeZone())->format($format), $dates);
        $params = $schedule->allDay ? ';VALUE=DATE' : ";TZID=$schedule->tz";
        return $name . $params . ':' . implode(',', $values);
    }

    /**
     * Records that the calendar uses a zone over a range, widened by a year on
     * each side so the VTIMEZONE includes the transitions around it. Several
     * calls for one zone merge into the widest range.
     */
    private function _coverTimeZone(string $tz, DateTimeInterface $from, DateTimeInterface $to): void
    {
        $fromTs = $from->getTimestamp() - 366 * 86400;
        $toTs = $to->getTimestamp() + 366 * 86400;
        if (isset($this->timeZones[$tz])) {
            $fromTs = min($fromTs, $this->timeZones[$tz][0]);
            $toTs = max($toTs, $this->timeZones[$tz][1]);
        }
        $this->timeZones[$tz] = [$fromTs, $toTs];
    }

    /**
     * A VTIMEZONE listing each real offset change in the range, built from
     * PHP's tz database so it matches how dates were expanded. Called by
     * render() for each zone recorded by coverTimeZone().
     */
    private static function vtimezone(string $tz, int $from, int $to): string
    {
        // getTransitions() lists the offset in force at $from, then each change.
        $transitions = (new DateTimeZone($tz))->getTransitions($from, $to) ?: [];
        $lines = ["BEGIN:VTIMEZONE", "TZID:$tz"];

        // A zone with no changes in range (e.g. UTC, or no DST): one fixed offset.
        if (count($transitions) < 2) {
            $offset = self::offset($transitions[0]['offset'] ?? 0);
            $abbr = $transitions[0]['abbr'] ?? 'UTC';
            array_push($lines, 'BEGIN:STANDARD', 'DTSTART:19700101T000000', "TZOFFSETFROM:$offset", "TZOFFSETTO:$offset", "TZNAME:$abbr", 'END:STANDARD');
        } else {
            // One STANDARD or DAYLIGHT block per change, remembering the last
            // of each kind for the yearly rule below.
            $components = [];
            $lastOfType = [];
            for ($i = 1, $n = count($transitions); $i < $n; $i++) {
                $prev = $transitions[$i - 1];
                $cur = $transitions[$i];
                $type = $cur['isdst'] ? 'DAYLIGHT' : 'STANDARD';
                // DTSTART is the local time just before the change, in the old offset.
                $localTs = $cur['ts'] + $prev['offset'];
                $components[] = [
                    'type' => $type,
                    'ts' => $localTs,
                    'lines' => [
                        'DTSTART:' . gmdate('Ymd\THis', $localTs),
                        'TZOFFSETFROM:' . self::offset($prev['offset']),
                        'TZOFFSETTO:' . self::offset($cur['offset']),
                        'TZNAME:' . $cur['abbr'],
                    ],
                ];
                $lastOfType[$type] = count($components) - 1;
            }

            // Repeat the final DST pattern yearly so open-ended series stay correct
            // past the listed range (e.g. "2nd Sunday of March" → BYDAY=2SU).
            if (count($lastOfType) === 2) {
                foreach ($lastOfType as $index) {
                    $ts = $components[$index]['ts'];
                    $day = (int)gmdate('j', $ts);
                    // In the month's last 7 days it's "last"; otherwise 1st–4th.
                    $nth = $day + 7 > (int)gmdate('t', $ts) ? -1 : intdiv($day - 1, 7) + 1;
                    $weekday = strtoupper(substr(gmdate('D', $ts), 0, 2));
                    $components[$index]['lines'][] = sprintf('RRULE:FREQ=YEARLY;BYMONTH=%d;BYDAY=%d%s', (int)gmdate('n', $ts), $nth, $weekday);
                }
            }

            foreach ($components as $component) {
                $lines[] = "BEGIN:{$component['type']}";
                array_push($lines, ...$component['lines']);
                $lines[] = "END:{$component['type']}";
            }
        }

        $lines[] = 'END:VTIMEZONE';
        return implode("\r\n", $lines);
    }

    /** UTC offset in RFC 5545 form, e.g. `-0500`. */
    private static function offset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);
        return sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /**
     * A BEGIN/END block with its property lines, e.g. a VEVENT.
     *
     * @param array<string, string> $props Property name (with params) => value
     * @param string[] $raw Pre-built property lines
     */
    private static function component(string $name, array $props, array $raw = []): string
    {
        $lines = ["BEGIN:$name"];
        foreach ($props as $prop => $value) {
            $lines[] = "$prop:$value";
        }
        foreach ($raw as $line) {
            $lines[] = $line;
        }
        $lines[] = "END:$name";
        return implode("\r\n", $lines);
    }

    /** A UTC date-time value, e.g. `20261101T120000Z`. */
    private static function utc(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * Plain text from HTML, keeping its structure: paragraphs and headings
     * become blank-line separated, `<br>` a line break, list items `- ` lines.
     * Used for rich-text and template descriptions (IcsDetails).
     */
    public static function htmlToText(string $html): string
    {
        // Mark structure with newlines before the tags are stripped.
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<li[^>]*>/i', "\n- ", $html);
        $html = preg_replace('/<\/(p|div|h[1-6]|blockquote|ul|ol|table|tr|pre|figure)>/i', "\n\n", $html);

        // Strip tags and decode entities; non-breaking spaces become spaces.
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        // Tidy whitespace: collapse runs per line, at most one blank line in a row.
        $lines = array_map(fn($line) => trim(preg_replace('/[ \t]+/', ' ', $line)), explode("\n", $text));
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /** `GEO` value from `[latitude, longitude]`, or null unless both are valid. Lets calendar apps pin the location. */
    private static function geo(?array $coords): ?string
    {
        [$lat, $lon] = array_values($coords ?? []) + [null, null];
        if (!is_numeric($lat) || !is_numeric($lon) || abs((float)$lat) > 90 || abs((float)$lon) > 180) {
            return null;
        }
        // Six decimals (~10 cm) with trailing zeros trimmed.
        return rtrim(rtrim(sprintf('%.6F', $lat), '0'), '.') . ';' . rtrim(rtrim(sprintf('%.6F', $lon), '0'), '.');
    }

    /** Escapes a TEXT value (SUMMARY, DESCRIPTION, LOCATION…): backslashes, `;` and `,`, and newlines as `\n`. */
    public static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $value);
    }

    /**
     * Folds lines longer than 75 octets without splitting UTF-8 characters, as
     * RFC 5545 requires. Used by render() on every line.
     */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $current = '';
        $limit = 75;
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $out .= $current . "\r\n ";
                $current = '';
                $limit = 74; // continuation lines start with a space
            }
            $current .= $char;
        }
        return $out . $current;
    }
}
