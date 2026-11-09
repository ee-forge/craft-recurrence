<?php

namespace eeforge\recurrence\tests;

use DateTimeImmutable;
use eeforge\recurrence\helpers\Ics;
use eeforge\recurrence\models\Schedule;
use craft\test\TestCase;

/**
 * ICS output: series, overrides, exclusions, VTIMEZONEs, escaping and folding,
 * checked line by line against RFC 5545.
 */
class IcsTest extends TestCase
{
    /** One schedule rendered as a calendar, with fixed metadata so output is stable. */
    private function render(array $data, array $meta = []): string
    {
        $ics = new Ics('Events');
        $ics->addSchedule(Schedule::fromArray($data, 'America/New_York')->withLimits(24, 12, 1000), $meta + [
            'uid' => 'abc@example.com',
            'title' => 'Bible Study',
            'updated' => new DateTimeImmutable('2026-11-01 12:00:00 UTC'),
        ]);
        return $ics->render();
    }

    public function testRecurringSeriesWithOverridesAndExclusions(): void
    {
        $out = $this->render([
            'start' => '2026-11-05T19:00',
            'end' => '2026-11-05T20:30',
            'freq' => 'weekly',
            'ends' => 'until',
            'until' => '2027-05-27',
            'exdates' => ['2026-11-26'],
            'overrides' => [
                '2026-12-03' => ['cancelled' => true],
                '2026-12-10' => ['start' => '2026-12-09T18:00', 'note' => 'Moved; fellowship hall'],
            ],
        ], ['description' => 'Weekly study, all welcome']);

        $this->assertStringContainsString("BEGIN:VCALENDAR\r\n", $out);
        $this->assertStringContainsString("X-WR-CALNAME:Events\r\n", $out);
        $this->assertStringContainsString("DTSTART;TZID=America/New_York:20261105T190000\r\n", $out);
        $this->assertStringContainsString("DTEND;TZID=America/New_York:20261105T203000\r\n", $out);
        $this->assertStringContainsString("RRULE:FREQ=WEEKLY;INTERVAL=1;BYDAY=TH;UNTIL=20270528T035959Z\r\n", $out);
        // Skipped date and cancelled occurrence both become EXDATEs at their original times.
        $this->assertStringContainsString("EXDATE;TZID=America/New_York:20261126T190000,20261203T190000\r\n", $out);
        // The rescheduled occurrence overrides its instance of the series.
        $this->assertStringContainsString("RECURRENCE-ID;TZID=America/New_York:20261210T190000\r\n", $out);
        $this->assertStringContainsString("DTSTART;TZID=America/New_York:20261209T180000\r\n", $out);
        $this->assertStringContainsString('DESCRIPTION:Moved\; fellowship hall\n\nWeekly study\, all welcome', $out);
        $this->assertSame(2, substr_count($out, 'BEGIN:VEVENT'));
        $this->assertSame(2, substr_count($out, 'UID:abc@example.com'));
        // VTIMEZONE covers the DST changes around the series.
        $this->assertStringContainsString("BEGIN:VTIMEZONE\r\nTZID:America/New_York\r\n", $out);
        $this->assertStringContainsString("BEGIN:DAYLIGHT\r\nDTSTART:20270314T020000\r\nTZOFFSETFROM:-0500\r\nTZOFFSETTO:-0400\r\n", $out);
        $this->assertStringContainsString("BEGIN:STANDARD\r\nDTSTART:20261101T020000\r\nTZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\n", $out);
        // The last DST pattern repeats so open-ended series stay right past the range.
        $this->assertStringContainsString("RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU\r\n", $out);
        $this->assertStringContainsString("RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU\r\n", $out);
        $this->assertSame(2, substr_count($out, 'RRULE:FREQ=YEARLY;BYMONTH='));
    }

    public function testSeriesStartingInDstGapKeepsTypedTimeAndLength(): void
    {
        // 02:30 doesn't exist on Mar 14 2027 in New York; it resolves to 03:30 EDT.
        $out = $this->render(['start' => '2027-03-14T02:30', 'end' => '2027-03-14T03:30', 'freq' => 'weekly']);

        $this->assertStringContainsString("DTSTART;TZID=America/New_York:20270314T033000\r\n", $out);
        $this->assertStringContainsString("DTEND;TZID=America/New_York:20270314T043000\r\n", $out);
        // Pinned to the typed time, so apps put later dates at 02:30, not 03:30.
        $this->assertStringContainsString("RRULE:FREQ=WEEKLY;INTERVAL=1;BYDAY=SU;BYHOUR=2;BYMINUTE=30;BYSECOND=0\r\n", $out);
    }

    public function testRuleIsNotPinnedOutsideDstGaps(): void
    {
        $out = $this->render(['start' => '2027-03-07T02:30', 'end' => '2027-03-07T03:30', 'freq' => 'weekly']);

        $this->assertStringContainsString("RRULE:FREQ=WEEKLY;INTERVAL=1;BYDAY=SU\r\n", $out);
    }

    public function testEuropeanDstUsesLastSunday(): void
    {
        $ics = new Ics();
        $ics->addSchedule(Schedule::fromArray(['start' => '2026-11-05T19:00', 'freq' => 'weekly'], 'Europe/London')->withLimits(24, 12, 1000), ['uid' => 'x', 'title' => 'x']);
        $out = $ics->render();

        $this->assertStringContainsString("RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\n", $out);
        $this->assertStringContainsString("RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\n", $out);
    }

    public function testAllDayEventsUseDateValues(): void
    {
        $out = $this->render([
            'start' => '2026-12-24',
            'end' => '2026-12-25',
            'allDay' => true,
            'freq' => 'yearly',
            'ends' => 'until',
            'until' => '2030-12-24',
        ]);

        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261224\r\n", $out);
        // DTEND is exclusive for all-day events.
        $this->assertStringContainsString("DTEND;VALUE=DATE:20261226\r\n", $out);
        $this->assertStringContainsString("RRULE:FREQ=YEARLY;INTERVAL=1;UNTIL=20301224\r\n", $out);
    }

    public function testCancelledOneOffIsMarkedCancelled(): void
    {
        $out = $this->render([
            'start' => '2026-08-24T15:00',
            'end' => '2026-08-24T17:00',
            'overrides' => ['2026-08-24' => ['cancelled' => true]],
        ]);

        $this->assertStringContainsString("STATUS:CANCELLED\r\n", $out);
        $this->assertStringNotContainsString('RRULE', strstr($out, 'BEGIN:VEVENT'));
    }

    public function testHtmlToTextKeepsStructure(): void
    {
        $html = "<h2>Bring</h2><p>A Bible &amp; a&nbsp;friend.<br>Coffee at 6:30.</p>\n<ul><li>Snacks</li><li>Chairs</li></ul><p>  Questions?  </p>";
        $this->assertSame("Bring\n\nA Bible & a friend.\nCoffee at 6:30.\n\n- Snacks\n- Chairs\n\nQuestions?", Ics::htmlToText($html));
        $this->assertSame('Plain text stays', Ics::htmlToText('Plain text stays'));
    }

    public function testGeoIsWrittenOnlyForValidCoordinates(): void
    {
        $data = ['start' => '2026-11-05T19:00', 'end' => '2026-11-05T20:00'];
        $this->assertStringContainsString("GEO:33.712345;-80.1\r\n", $this->render($data, ['geo' => ['33.712345', '-80.100000']]));
        $this->assertStringNotContainsString('GEO:', $this->render($data, ['geo' => ['95', '10']]));
        $this->assertStringNotContainsString('GEO:', $this->render($data, ['geo' => null]));
    }

    public function testLongLinesAreFoldedOnCharacterBoundaries(): void
    {
        $long = str_repeat('Café fellowship — ', 10);
        $folded = Ics::fold('DESCRIPTION:' . $long);

        foreach (explode("\r\n", $folded) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'));
        }
        $this->assertSame('DESCRIPTION:' . $long, str_replace("\r\n ", '', $folded));
    }
}
