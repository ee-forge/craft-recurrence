<?php

namespace eeforge\recurrence\tests;

use craft\elements\Entry;
use craft\test\TestCase;
use eeforge\recurrence\fields\Recurrence;
use eeforge\recurrence\models\Schedule;
use eeforge\recurrence\Plugin;

/**
 * The field's value lifecycle: normalizing what's stored, posted or imported,
 * serializing it back, and validating it.
 */
class RecurrenceFieldTest extends TestCase
{
    private const TZ = 'America/New_York';

    private Recurrence $field;

    protected function _before(): void
    {
        $this->field = new Recurrence(['handle' => 'eventSchedule']);
    }

    public function testNormalizesAnArray(): void
    {
        $value = $this->field->normalizeValue(['tz' => self::TZ, 'start' => '2026-11-05T19:00', 'freq' => 'weekly'], null);

        $this->assertInstanceOf(Schedule::class, $value);
        $this->assertSame(self::TZ, $value->tz);
        $this->assertSame('2026-11-05T19:00', $value->start);
        $this->assertSame(Schedule::FREQ_WEEKLY, $value->freq);
    }

    public function testNormalizesStoredJson(): void
    {
        $value = $this->field->normalizeValue('{"tz":"America/New_York","start":"2026-11-05T19:00"}', null);

        $this->assertInstanceOf(Schedule::class, $value);
        $this->assertSame('2026-11-05T19:00', $value->start);
    }

    public function testNormalizesAnImportedRfcRule(): void
    {
        $value = $this->field->normalizeValue("DTSTART;TZID=America/New_York:20261105T190000\nRRULE:FREQ=WEEKLY;BYDAY=TH;COUNT=4", null);

        $this->assertInstanceOf(Schedule::class, $value);
        $this->assertSame(self::TZ, $value->tz);
        $this->assertSame('2026-11-05T19:00', $value->start);
        $this->assertSame('2026-11-05T20:00', $value->end, 'imported rules get the default duration');
        $this->assertSame(['TH'], $value->byDay);
        $this->assertSame(Schedule::ENDS_COUNT, $value->ends);
        $this->assertSame(4, $value->count);
    }

    public function testEmptyValuesNormalizeToNull(): void
    {
        foreach ([null, '', [], ['freq' => 'weekly'], 'not a rule'] as $empty) {
            $this->assertNull($this->field->normalizeValue($empty, null), var_export($empty, true));
        }
    }

    public function testNormalizedValuesCarryThePluginLimits(): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $value = $this->field->normalizeValue(['tz' => self::TZ, 'start' => '2026-11-05T19:00'], null);

        $this->assertSame($settings->horizonMonths, $value->getHorizonMonths());
        $this->assertSame($settings->pastMonths, $value->getPastMonths());
        $this->assertSame($settings->maxOccurrences, $value->getMaxOccurrences());
    }

    public function testSerializeRoundTrips(): void
    {
        $value = $this->field->normalizeValue(['tz' => self::TZ, 'start' => '2026-11-05T19:00', 'end' => '2026-11-05T20:30', 'freq' => 'weekly', 'byDay' => ['TH']], null);
        $serialized = $this->field->serializeValue($value, null);

        $this->assertEquals($value->toArray(), $serialized);
        $this->assertEquals($value->toArray(), $this->field->normalizeValue($serialized, null)->toArray());
    }

    public function testValidationRejectsAnEndBeforeTheStart(): void
    {
        $errors = $this->field->validationErrors($this->_schedule(['start' => '2026-11-05T19:00', 'end' => '2026-11-05T18:00']));

        $this->assertSame(['The end must be after the start.'], $errors);
    }

    public function testValidationRequiresAnUntilDate(): void
    {
        $errors = $this->field->validationErrors($this->_schedule(['start' => '2026-11-05T19:00', 'freq' => 'weekly', 'ends' => 'until']));

        $this->assertSame(['Choose the date the series ends on.'], $errors);
    }

    public function testValidationRequiresACount(): void
    {
        $errors = $this->field->validationErrors($this->_schedule(['start' => '2026-11-05T19:00', 'freq' => 'weekly', 'ends' => 'count']));

        $this->assertSame(['Enter how many times the event repeats.'], $errors);
    }

    public function testValidSchedulesHaveNoErrors(): void
    {
        $errors = $this->field->validationErrors($this->_schedule(['start' => '2026-11-05T19:00', 'end' => '2026-11-05T20:30', 'freq' => 'weekly', 'ends' => 'count', 'count' => 4]));

        $this->assertSame([], $errors);
    }

    public function testPreviewHtmlIsTheEncodedSummary(): void
    {
        $value = $this->_schedule(['start' => '2026-11-05T19:00', 'freq' => 'weekly', 'byDay' => ['TH']]);

        $this->assertSame('Weekly on Thu at 7:00 PM', $this->field->getPreviewHtml($value, new Entry()));
    }

    private function _schedule(array $data): Schedule
    {
        return $this->field->normalizeValue(['tz' => self::TZ] + $data, null);
    }
}
