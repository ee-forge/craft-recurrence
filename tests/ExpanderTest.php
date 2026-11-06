<?php

namespace eeforge\recurrence\tests;

use DateTimeImmutable;
use DateTimeZone;
use eeforge\recurrence\models\Occurrence;
use eeforge\recurrence\models\Schedule;
use eeforge\recurrence\services\Expander;
use PHPUnit\Framework\TestCase;

/**
 * Expansion engine: which dates a Schedule produces, their keys, overrides,
 * and DST handling. Runs without Craft (see tests/bootstrap.php).
 */
class ExpanderTest extends TestCase
{
    private const TZ = 'America/New_York';

    /**
     * A schedule from field-style data, in New York unless `tz` is given, with
     * test limits (24 months ahead, 12 back, 1000 dates) in place of the
     * plugin settings the field would pass.
     */
    private function schedule(array $data): Schedule
    {
        return Schedule::fromArray($data, self::TZ)->withLimits(24, 12, 1000);
    }

    /** A New York local time, for query bounds. */
    private function at(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone(self::TZ));
    }

    /**
     * Start times of occurrences, formatted for easy comparison.
     *
     * @param Occurrence[] $occurrences
     */
    private function starts(array $occurrences, string $format = 'Y-m-d H:i'): array
    {
        return array_map(fn(Occurrence $o) => $o->start->format($format), $occurrences);
    }

    /**
     * Occurrence keys, in order.
     *
     * @param Occurrence[] $occurrences
     */
    private function keys(array $occurrences): array
    {
        return array_map(fn(Occurrence $o) => $o->key, $occurrences);
    }

    public function testOneOffEvent(): void
    {
        $s = $this->schedule(['start' => '2026-08-24T15:00', 'end' => '2026-08-24T17:00']);

        $all = $s->between($this->at('2026-01-01'), $this->at('2027-01-01'));
        $this->assertSame(['2026-08-24 15:00'], $this->starts($all));
        $this->assertSame('2026-08-24 17:00', $all[0]->end->format('Y-m-d H:i'));
        $this->assertSame('2026-08-24', $all[0]->key);
        $this->assertFalse($s->getIsRecurring());
    }

    public function testWeeklyKeepsLocalTimeAcrossDst(): void
    {
        // DST ends Nov 1 2026 and starts Mar 14 2027 in New York.
        $s = $this->schedule(['start' => '2026-10-22T19:00', 'end' => '2026-10-22T20:30', 'freq' => 'weekly']);

        $occ = $s->between($this->at('2026-10-20'), $this->at('2026-11-20'));
        $this->assertSame(['2026-10-22 19:00', '2026-10-29 19:00', '2026-11-05 19:00', '2026-11-12 19:00', '2026-11-19 19:00'], $this->starts($occ));
        foreach ($occ as $o) {
            $this->assertSame('20:30', $o->end->format('H:i'));
        }
        // The UTC instant moves by an hour while the local time stays put.
        $this->assertSame('23:00', $occ[1]->start->setTimezone(new DateTimeZone('UTC'))->format('H:i'));
        $this->assertSame('00:00', $occ[2]->start->setTimezone(new DateTimeZone('UTC'))->format('H:i'));

        $spring = $s->between($this->at('2027-03-08'), $this->at('2027-03-20'));
        $this->assertSame(['2027-03-11 19:00', '2027-03-18 19:00'], $this->starts($spring));
    }

    public function testOneOffStartingInDstGapKeepsItsLength(): void
    {
        // 02:30 doesn't exist on Mar 14 2027 in New York; it resolves to 03:30 EDT.
        $s = $this->schedule(['start' => '2027-03-14T02:30', 'end' => '2027-03-14T03:30']);

        $occ = $s->between($this->at('2027-03-13'), $this->at('2027-03-15'));
        $this->assertCount(1, $occ);
        $this->assertSame('2027-03-14 03:30 -04:00', $occ[0]->start->format('Y-m-d H:i P'));
        $this->assertSame('2027-03-14 04:30 -04:00', $occ[0]->end->format('Y-m-d H:i P'));
        $this->assertSame('2027-03-14 04:30', $s->getEndLocal()->format('Y-m-d H:i'));
    }

    public function testSeriesStartingInDstGapKeepsItsLength(): void
    {
        $s = $this->schedule(['start' => '2027-03-14T02:30', 'end' => '2027-03-14T03:30', 'freq' => 'weekly']);

        $occ = $s->between($this->at('2027-03-13'), $this->at('2027-03-22'));
        // Only the first date is moved; later ones keep the typed 02:30.
        $this->assertSame(['2027-03-14 03:30', '2027-03-21 02:30'], $this->starts($occ));
        foreach ($occ as $o) {
            $this->assertSame(3600, $o->end->getTimestamp() - $o->start->getTimestamp());
        }
    }

    public function testRescheduleIntoDstGapKeepsItsLength(): void
    {
        $s = $this->schedule([
            'start' => '2027-03-07T19:00',
            'end' => '2027-03-07T20:00',
            'freq' => 'weekly',
            'overrides' => ['2027-03-14' => ['start' => '2027-03-14T02:30', 'end' => '2027-03-14T04:00']],
        ]);

        $occ = $s->between($this->at('2027-03-13'), $this->at('2027-03-15'));
        $this->assertCount(1, $occ);
        $this->assertSame('2027-03-14 03:30', $occ[0]->start->format('Y-m-d H:i'));
        $this->assertSame('2027-03-14 05:00', $occ[0]->end->format('Y-m-d H:i'));
        $this->assertTrue($occ[0]->rescheduled);
    }

    public function testAllDayEndsOnItsOwnDayWhereDstStartsAtMidnight(): void
    {
        // In Santiago, Sep 6 2026 starts at 01:00: midnight doesn't exist.
        $s = $this->schedule(['tz' => 'America/Santiago', 'start' => '2026-09-05', 'end' => '2026-09-05', 'allDay' => true, 'freq' => 'daily']);

        $tz = new DateTimeZone('America/Santiago');
        $occ = $s->between(new DateTimeImmutable('2026-09-05', $tz), new DateTimeImmutable('2026-09-08', $tz));
        $this->assertSame(['2026-09-05', '2026-09-06', '2026-09-07'], $this->starts($occ, 'Y-m-d'));
        foreach ($occ as $o) {
            $this->assertSame($o->start->format('Y-m-d') . ' 23:59:59', $o->end->format('Y-m-d H:i:s'));
            $this->assertFalse($o->getIsMultiDay());
        }
    }

    public function testEndKeepsItsLocalTimeAcrossFallBack(): void
    {
        // 01:30 happens twice on Nov 1 2026; the first (EDT) is used, and the end
        // keeps its local 02:30 (EST), so that date runs two hours, not one.
        $s = $this->schedule(['start' => '2026-10-25T01:30', 'end' => '2026-10-25T02:30', 'freq' => 'weekly']);

        $occ = $s->between($this->at('2026-10-31'), $this->at('2026-11-02'));
        $this->assertCount(1, $occ);
        $this->assertSame('2026-11-01 01:30 -04:00', $occ[0]->start->format('Y-m-d H:i P'));
        $this->assertSame('2026-11-01 02:30 -05:00', $occ[0]->end->format('Y-m-d H:i P'));
    }

    public function testMultiDayAllDayEventKeepsItsDaysAcrossDst(): void
    {
        $s = $this->schedule(['start' => '2026-10-31', 'end' => '2026-11-02', 'allDay' => true, 'freq' => 'yearly']);

        $occ = $s->between($this->at('2026-10-01'), $this->at('2026-12-01'));
        $this->assertCount(1, $occ);
        $this->assertSame('2026-10-31 00:00', $occ[0]->start->format('Y-m-d H:i'));
        $this->assertSame('2026-11-02 23:59:59', $occ[0]->end->format('Y-m-d H:i:s'));
        $this->assertTrue($occ[0]->getIsMultiDay());
    }

    public function testOvernightEventEndsTheNextDay(): void
    {
        $s = $this->schedule(['start' => '2026-12-31T22:00', 'end' => '2027-01-01T01:00', 'freq' => 'yearly']);

        $occ = $s->between($this->at('2027-12-01'), $this->at('2028-01-15'));
        $this->assertSame('2027-12-31 22:00', $occ[0]->start->format('Y-m-d H:i'));
        $this->assertSame('2028-01-01 01:00', $occ[0]->end->format('Y-m-d H:i'));
    }

    public function testMonthlySecondThursday(): void
    {
        $s = $this->schedule(['start' => '2026-11-12T18:00', 'freq' => 'monthly', 'monthlyMode' => 'nth']);

        $this->assertSame(
            ['2026-11-12', '2026-12-10', '2027-01-14', '2027-02-11'],
            $this->starts($s->between($this->at('2026-11-01'), $this->at('2027-03-01')), 'Y-m-d'),
        );
        $this->assertSame('Monthly on the second Thursday at 6:00 PM', $s->getSummary());
    }

    public function testMonthlyLastFriday(): void
    {
        $s = $this->schedule(['start' => '2026-11-27T12:00', 'freq' => 'monthly', 'monthlyMode' => 'last']);

        $this->assertSame(
            ['2026-11-27', '2026-12-25', '2027-01-29', '2027-02-26'],
            $this->starts($s->between($this->at('2026-11-01'), $this->at('2027-03-01')), 'Y-m-d'),
        );
    }

    public function testFifthWeekdayIsTreatedAsLast(): void
    {
        // Oct 29 2026 is the fifth Thursday; most months don't have one.
        $s = $this->schedule(['start' => '2026-10-29T18:00', 'freq' => 'monthly', 'monthlyMode' => 'nth']);

        $this->assertSame(
            ['2026-10-29', '2026-11-26', '2026-12-31'],
            $this->starts($s->between($this->at('2026-10-01'), $this->at('2027-01-01')), 'Y-m-d'),
        );
    }

    public function testMonthlyOnThe31stSkipsShortMonths(): void
    {
        $s = $this->schedule(['start' => '2026-10-31T10:00', 'freq' => 'monthly']);

        $this->assertSame(
            ['2026-10-31', '2026-12-31', '2027-01-31', '2027-03-31'],
            $this->starts($s->between($this->at('2026-10-01'), $this->at('2027-04-15')), 'Y-m-d'),
        );
    }

    public function testEveryOtherWeekMonWedUntil(): void
    {
        $s = $this->schedule([
            'start' => '2026-11-02T09:00',
            'freq' => 'weekly',
            'interval' => 2,
            'byDay' => ['MO', 'WE'],
            'ends' => 'until',
            'until' => '2026-12-02',
        ]);

        $this->assertSame(
            ['2026-11-02', '2026-11-04', '2026-11-16', '2026-11-18', '2026-11-30', '2026-12-02'],
            $this->starts($s->between($this->at('2026-01-01'), $this->at('2028-01-01')), 'Y-m-d'),
        );
        $this->assertFalse($s->getIsOpenEnded());
    }

    public function testStartThatDoesNotMatchTheRuleStillCounts(): void
    {
        // Thursday start, Mon/Wed rule.
        $s = $this->schedule(['start' => '2026-11-05T09:00', 'freq' => 'weekly', 'byDay' => ['MO', 'WE']]);

        $this->assertSame(
            ['2026-11-05', '2026-11-09', '2026-11-11'],
            $this->starts($s->between($this->at('2026-11-01'), $this->at('2026-11-12')), 'Y-m-d'),
        );
    }

    public function testCount(): void
    {
        $s = $this->schedule(['start' => '2026-11-03T07:00', 'freq' => 'daily', 'ends' => 'count', 'count' => 10]);

        $occ = $s->between($this->at('2026-01-01'), $this->at('2030-01-01'));
        $this->assertCount(10, $occ);
        $this->assertSame('2026-11-12', $occ[9]->start->format('Y-m-d'));
    }

    public function testRdateEqualToStartIsNotDuplicated(): void
    {
        // Solspace Calendar #467: selecting the start date again listed it twice.
        $s = $this->schedule([
            'start' => '2026-12-06T10:00',
            'freq' => 'dates',
            'rdates' => ['2026-12-06T10:00', '2026-12-13T10:00', '2026-12-24T19:00'],
        ]);

        $this->assertSame(
            ['2026-12-06 10:00', '2026-12-13 10:00', '2026-12-24 19:00'],
            $this->starts($s->between($this->at('2026-12-01'), $this->at('2027-01-01'))),
        );
        $this->assertTrue($s->getIsRecurring());
    }

    public function testTwoOccurrencesOnOneDayGetTimeSuffixedKeys(): void
    {
        $s = $this->schedule([
            'start' => '2026-12-24T17:00',
            'end' => '2026-12-24T18:00',
            'freq' => 'dates',
            'rdates' => ['2026-12-24T23:00', '2026-12-25T10:00'],
        ]);

        $occ = $s->between($this->at('2026-12-01'), $this->at('2027-01-01'));
        $this->assertSame(['2026-12-24-1700', '2026-12-24-2300', '2026-12-25'], $this->keys($occ));
        $this->assertSame('2026-12-24 23:00', $s->occurrence('2026-12-24-2300')?->start->format('Y-m-d H:i'));
        $this->assertNull($s->occurrence('2026-12-24'));
    }

    public function testExcludedDatesAreRemoved(): void
    {
        $s = $this->schedule(['start' => '2026-11-05T19:00', 'freq' => 'weekly', 'exdates' => ['2026-11-26']]);

        $this->assertSame(
            ['2026-11-19', '2026-12-03'],
            $this->starts($s->between($this->at('2026-11-15'), $this->at('2026-12-05')), 'Y-m-d'),
        );
        $this->assertNull($s->occurrence('2026-11-26'));
    }

    public function testCancelledOccurrenceStaysListedButIsSkippedByNext(): void
    {
        $s = $this->schedule([
            'start' => '2026-11-05T19:00',
            'end' => '2026-11-05T20:00',
            'freq' => 'weekly',
            'overrides' => ['2026-11-12' => ['cancelled' => true, 'note' => 'Snow day']],
        ]);

        $occ = $s->between($this->at('2026-11-10'), $this->at('2026-11-20'), includeCancelled: true);
        $this->assertSame(['2026-11-12', '2026-11-19'], $this->keys($occ));
        $this->assertTrue($occ[0]->cancelled);
        $this->assertSame('Snow day', $occ[0]->note);

        $this->assertSame('2026-11-19', $s->next(now: $this->at('2026-11-10'))?->key);
        $this->assertSame('2026-11-12', $s->next(includeCancelled: true, now: $this->at('2026-11-10'))?->key);
    }

    public function testCancelledDatesAreLeftOutByDefault(): void
    {
        $s = $this->schedule([
            'start' => '2026-11-05T19:00',
            'end' => '2026-11-05T20:00',
            'freq' => 'weekly',
            'ends' => 'count',
            'count' => 3,
            'overrides' => ['2026-11-12' => ['cancelled' => true]],
        ]);
        $now = $this->at('2026-11-01');

        $this->assertSame(['2026-11-05', '2026-11-19'], $this->keys($s->between($this->at('2026-11-01'), $this->at('2026-12-01'))));
        $this->assertSame(['2026-11-05', '2026-11-19'], $this->keys($s->upcoming(now: $now)));
        $this->assertSame(['2026-11-05', '2026-11-12', '2026-11-19'], $this->keys($s->upcoming(includeCancelled: true, now: $now)));
        $this->assertSame('2026-11-19', $s->previous(now: $this->at('2027-01-01'))?->key);
        $this->assertSame('2026-11-12', $s->previous(includeCancelled: true, now: $this->at('2026-11-15'))?->key);
    }

    public function testRescheduledOccurrenceKeepsItsOriginalKey(): void
    {
        $s = $this->schedule([
            'start' => '2026-11-05T19:00',
            'end' => '2026-11-05T20:30',
            'freq' => 'weekly',
            'overrides' => ['2026-11-26' => ['start' => '2026-11-24T18:00']],
        ]);

        $occ = $s->between($this->at('2026-11-22'), $this->at('2026-11-30'));
        $this->assertCount(1, $occ);
        $this->assertSame('2026-11-26', $occ[0]->key);
        $this->assertSame('2026-11-24 18:00', $occ[0]->start->format('Y-m-d H:i'));
        // No end given, so the series' length (1h30) carries over.
        $this->assertSame('2026-11-24 19:30', $occ[0]->end->format('Y-m-d H:i'));
        $this->assertTrue($occ[0]->rescheduled);
        $this->assertSame('2026-11-26 19:00', $occ[0]->originalStart->format('Y-m-d H:i'));

        $found = Expander::find($s, '2026-11-26');
        $this->assertSame('2026-11-24 18:00', $found?->start->format('Y-m-d H:i'));
    }

    public function testRescheduledUrlFollowsTheNewDate(): void
    {
        $s = $this->schedule([
            'start' => '2026-11-05T19:00',
            'freq' => 'weekly',
            'overrides' => ['2026-11-26' => ['start' => '2026-11-24T18:00']],
        ]);

        $occ = $s->between($this->at('2026-11-22'), $this->at('2026-11-30'));
        $this->assertSame('2026-11-24', $occ[0]->urlKey);

        $this->assertSame('2026-11-26', $s->occurrence('2026-11-24')?->key);
        $this->assertNull($s->occurrence('2026-11-26'));
        $this->assertSame('2026-11-24', Expander::find($s, '2026-11-26')?->urlKey);
    }

    public function testMovingOntoADayWithAnotherDateSuffixesBothUrls(): void
    {
        $s = $this->schedule([
            'start' => '2026-11-05T19:00',
            'freq' => 'weekly',
            'overrides' => ['2026-11-26' => ['start' => '2026-11-19T10:00']],
        ]);

        $occ = $s->between($this->at('2026-11-19'), $this->at('2026-11-20'));
        $this->assertSame(['2026-11-26', '2026-11-19'], $this->keys($occ));
        $this->assertSame(['2026-11-19-1000', '2026-11-19-1900'], array_map(fn($o) => $o->urlKey, $occ));

        $this->assertSame('2026-11-26', $s->occurrence('2026-11-19-1000')?->key);
        $this->assertSame('2026-11-19', $s->occurrence('2026-11-19-1900')?->key);
        $this->assertNull($s->occurrence('2026-11-19'));
    }

    public function testUrlKeyForAnOngoingDateThatStartedBeforeTheWindow(): void
    {
        $s = $this->schedule([
            'start' => '2026-11-05T19:00',
            'end' => '2026-11-05T20:00',
            'freq' => 'weekly',
            'overrides' => ['2026-11-26' => ['start' => '2026-11-19T10:00', 'end' => '2026-11-28T10:00']],
        ]);

        $occ = $s->between($this->at('2026-11-27'), $this->at('2026-11-28'));
        $this->assertSame(['2026-11-26'], $this->keys($occ));
        // 2026-11-19 is outside the expanded window but has its own 7pm date.
        $this->assertSame('2026-11-19-1000', $occ[0]->urlKey);
    }

    public function testMovingOntoTheExactTimeOfAnotherDateIsACollision(): void
    {
        $base = ['start' => '2026-11-05T19:00', 'freq' => 'weekly'];

        $this->assertSame([], Expander::collisions($this->schedule($base + [
            'overrides' => ['2026-11-26' => ['start' => '2026-11-19T10:00']],
        ])));
        $this->assertSame(['2026-11-26'], Expander::collisions($this->schedule($base + [
            'overrides' => ['2026-11-26' => ['start' => '2026-11-19T19:00']],
        ])));
    }

    public function testOccurrenceRescheduledIntoTheWindowFromOutsideIt(): void
    {
        $s = $this->schedule([
            'start' => '2026-11-05T19:00',
            'freq' => 'weekly',
            'overrides' => ['2026-12-31' => ['start' => '2026-11-14T10:00']],
        ]);

        $occ = $s->between($this->at('2026-11-13'), $this->at('2026-11-15'));
        $this->assertSame(['2026-12-31'], $this->keys($occ));
    }

    public function testInvalidKeysAreRejected(): void
    {
        $s = $this->schedule(['start' => '2026-11-05T19:00', 'freq' => 'weekly']);

        $this->assertNotNull($s->occurrence('2026-11-12'));
        $this->assertNull($s->occurrence('2026-11-13'));
        $this->assertNull($s->occurrence('2026-02-30'));
        $this->assertNull($s->occurrence('2026-11-12/../x'));
        $this->assertNull($s->occurrence('2026-10-29'));
    }

    public function testPreviousReturnsTheLatestStartedOccurrence(): void
    {
        $s = $this->schedule(['start' => '2026-11-05T19:00', 'freq' => 'weekly', 'ends' => 'count', 'count' => 3]);

        $this->assertSame('2026-11-19', $s->previous(now: $this->at('2027-01-01'))?->key);
        $this->assertSame('2026-11-12', $s->previous(now: $this->at('2026-11-15'))?->key);
        $this->assertNull($s->previous(now: $this->at('2026-11-01')));
        $this->assertNull($s->next(now: $this->at('2027-01-01')));
    }

    public function testOngoingOccurrenceIsIncluded(): void
    {
        $s = $this->schedule(['start' => '2026-11-05T19:00', 'end' => '2026-11-05T21:00', 'freq' => 'weekly']);

        $this->assertSame('2026-11-05', $s->next(now: $this->at('2026-11-05 20:00'))?->key);
    }

    public function testOpenEndedRuleIsCappedAtTheHorizon(): void
    {
        $s = $this->schedule(['start' => '2026-01-01T08:00', 'freq' => 'daily']);
        $s->withLimits(1, 12, 1000);

        $occ = $s->upcoming(1000, now: $this->at('2026-06-01'));
        $this->assertSame('2026-06-01', $occ[0]->key);
        // The horizon is exactly one month from now, so July 1 at 8am is past it.
        $this->assertSame('2026-06-30', end($occ)->key);
        $this->assertTrue($s->getIsOpenEnded());
    }

    public function testTimedDatesBeyondTheHorizonAreIncluded(): void
    {
        $s = $this->schedule([
            'start' => '2026-11-22T17:30',
            'end' => '2026-11-22T18:30',
            'freq' => 'dates',
            'rdates' => ['2027-11-21T17:30'],
        ]);
        $s->withLimits(1, 12, 1000);
        $now = $this->at('2026-11-06');

        $this->assertSame(['2026-11-22', '2027-11-21'], $this->keys($s->upcoming(now: $now)));
        $this->assertSame(['2026-11-22', '2027-11-21'], $this->keys($s->occurrencesTableDates($now)));

        $oneOff = $this->schedule(['start' => '2028-03-01T19:00', 'end' => '2028-03-01T20:00']);
        $oneOff->withLimits(1, 12, 1000);
        $this->assertSame(['2028-03-01'], $this->keys($oneOff->upcoming(now: $now)));
    }

    public function testMaxOccurrencesCap(): void
    {
        $s = $this->schedule(['start' => '2026-01-01T08:00', 'freq' => 'daily']);
        $s->withLimits(24, 12, 5);

        $this->assertCount(5, $s->between($this->at('2026-01-01'), $this->at('2027-01-01')));
    }

    public function testLatestKeepsTheLastDatesUnderTheCap(): void
    {
        $s = $this->schedule(['start' => '2026-01-01T08:00', 'freq' => 'daily']);
        $s->withLimits(24, 12, 5);

        $this->assertSame(
            ['2026-12-27', '2026-12-28', '2026-12-29', '2026-12-30', '2026-12-31'],
            $this->starts($s->between($this->at('2026-01-01'), $this->at('2027-01-01'), latest: true), 'Y-m-d'),
        );
        $this->assertSame(
            ['2026-12-30', '2026-12-31'],
            $this->starts($s->between($this->at('2026-01-01'), $this->at('2027-01-01'), 2, latest: true), 'Y-m-d'),
        );
    }

    public function testPreviousOfASeriesLongerThanTheCapIsItsLatestDate(): void
    {
        $s = $this->schedule(['start' => '2026-01-01T08:00', 'freq' => 'daily']);
        $s->withLimits(24, 12, 5);

        $this->assertSame('2026-12-31', $s->previous(now: $this->at('2027-01-01 07:00'))?->key);
    }

    public function testOccurrencesTableKeepsUpcomingDatesFirst(): void
    {
        // A daily series running for a year, with room for 10 dates: the
        // 10 soonest upcoming ones, and no past dates.
        $s = $this->schedule(['start' => '2025-06-01T08:00', 'freq' => 'daily']);
        $s->withLimits(18, 12, 10);
        $now = $this->at('2026-06-01 12:00');

        $dates = $this->starts($s->occurrencesTableDates($now), 'Y-m-d');
        $this->assertCount(10, $dates);
        $this->assertSame('2026-06-02', $dates[0]);
        $this->assertSame('2026-06-11', end($dates));
    }

    public function testOccurrencesTableFillsWithTheMostRecentPastDates(): void
    {
        // Three weekly dates left, room for five: the two latest past dates
        // fill the rest, oldest first. Cancelled dates are stored too.
        $s = $this->schedule([
            'start' => '2026-01-01T19:00',
            'end' => '2026-01-01T20:00',
            'freq' => 'weekly',
            'ends' => 'until',
            'until' => '2026-03-26',
            'overrides' => ['2026-03-12' => ['cancelled' => true]],
        ]);
        $s->withLimits(18, 12, 5);
        $now = $this->at('2026-03-10 12:00');

        $occ = $s->occurrencesTableDates($now);
        $this->assertSame(['2026-02-26', '2026-03-05', '2026-03-12', '2026-03-19', '2026-03-26'], $this->keys($occ));
        $this->assertTrue($occ[2]->cancelled);
    }

    public function testOccurrencesTableCountsAnOngoingDateOnce(): void
    {
        $s = $this->schedule(['start' => '2026-01-01T19:00', 'end' => '2026-01-01T21:00', 'freq' => 'weekly', 'ends' => 'count', 'count' => 4]);
        $s->withLimits(18, 12, 3);
        // During the third date (Jan 15, 19:00–21:00).
        $now = $this->at('2026-01-15 20:00');

        $this->assertSame(['2026-01-08', '2026-01-15', '2026-01-22'], $this->keys($s->occurrencesTableDates($now)));
    }

    public function testExpandingWithoutLimitsFails(): void
    {
        $s = Schedule::fromArray(['start' => '2026-01-01T08:00', 'freq' => 'daily'], self::TZ);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('withLimits()');
        $s->upcoming(5);
    }

    public function testRRuleString(): void
    {
        $s = $this->schedule(['start' => '2026-11-02T09:00', 'freq' => 'weekly', 'interval' => 2, 'byDay' => ['MO', 'WE'], 'ends' => 'until', 'until' => '2026-12-02']);
        $this->assertSame('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE;UNTIL=20261203T045959Z', $s->getRRuleString());

        $s = $this->schedule(['start' => '2026-11-27T12:00', 'freq' => 'monthly', 'monthlyMode' => 'last', 'ends' => 'count', 'count' => 3]);
        $this->assertSame('FREQ=MONTHLY;INTERVAL=1;BYDAY=-1FR;COUNT=3', $s->getRRuleString());
    }

    public function testRoundTripThroughArray(): void
    {
        $data = [
            'start' => '2026-11-05T19:00',
            'end' => '2026-11-05T20:00',
            'freq' => 'weekly',
            'byDay' => ['th', 'XX'],
            'exdates' => ['2026-11-26', 'not-a-date'],
            'overrides' => ['2026-12-03' => ['note' => '  Fellowship hall  ', 'cancelled' => false], 'bad' => ['cancelled' => true]],
        ];
        $s = $this->schedule($data);

        $this->assertSame(['TH'], $s->byDay);
        $this->assertSame(['2026-11-26'], $s->exdates);
        $this->assertSame(['2026-12-03' => ['note' => 'Fellowship hall']], $s->overrides);

        $again = Schedule::fromArray(json_decode(json_encode($s), true), self::TZ);
        $this->assertEquals($s->toArray(), $again->toArray());
    }
}
