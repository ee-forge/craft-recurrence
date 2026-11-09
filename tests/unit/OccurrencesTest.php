<?php

namespace eeforge\recurrence\tests;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\test\TestCase;
use DateTimeImmutable;
use DateTimeZone;
use eeforge\recurrence\models\Occurrence;
use eeforge\recurrence\Plugin;
use eeforge\recurrence\queries\OccurrenceQuery;

/**
 * Saving entries with a Recurrence field, and reading their dates back from
 * the occurrences table: the service, OccurrenceQuery and occursBetween().
 *
 * The `events` section, its `event` entry type and the `eventSchedule` field
 * come from the suite's project config (tests/_craft/project). The Craft
 * module rolls the database back after every test.
 */
class OccurrencesTest extends TestCase
{
    private const TZ = 'America/New_York';

    private Section $section;
    private EntryType $entryType;

    /** The first of the four weekly dates each test schedules, a month out so it's always upcoming. */
    private DateTimeImmutable $start;

    protected function _before(): void
    {
        $entries = Craft::$app->getEntries();
        $this->section = $entries->getSectionByHandle('events');
        $this->entryType = $entries->getEntryTypeByHandle('event');
        $this->start = (new DateTimeImmutable('first thursday of next month', new DateTimeZone(self::TZ)))->setTime(19, 0);
    }

    public function testSavingAnEntryWritesItsDates(): void
    {
        $entry = $this->_saveEntry('Bible study');

        $this->assertSame($this->_weeklyKeys(4), $this->_storedKeys($entry));
    }

    public function testClearingTheFieldRemovesItsDates(): void
    {
        $entry = $this->_saveEntry('Bible study');
        $entry->setFieldValue('eventSchedule', null);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry));

        $this->assertSame([], $this->_storedKeys($entry));
    }

    public function testDraftsAreNotStored(): void
    {
        $entry = $this->_saveEntry('Bible study');
        $draft = Craft::$app->getDrafts()->createDraft($entry);
        $draft->setFieldValue('eventSchedule', $this->_scheduleData($this->start, ['count' => 2]));
        $this->assertTrue(Craft::$app->getElements()->saveElement($draft));

        $this->assertSame([], $this->_storedKeys($draft));
        $this->assertSame($this->_weeklyKeys(4), $this->_storedKeys($entry), 'the canonical entry keeps its dates');
    }

    public function testQueryListsDatesAcrossEntriesInOrder(): void
    {
        $study = $this->_saveEntry('Bible study');
        $choir = $this->_saveEntry('Choir', $this->start->modify('+1 day'));

        $occurrences = (new OccurrenceQuery())->section('events')->all();

        $this->assertCount(8, $occurrences);
        $this->assertContainsOnlyInstancesOf(Occurrence::class, $occurrences);
        $this->assertSame(
            [$study->id, $choir->id, $study->id, $choir->id, $study->id, $choir->id, $study->id, $choir->id],
            array_map(fn(Occurrence $o): int => $o->element->id, $occurrences),
        );
    }

    public function testQueryCountsAndPages(): void
    {
        $this->_saveEntry('Bible study');
        $query = (new OccurrenceQuery())->section('events');

        $this->assertSame(4, $query->count());
        $this->assertCount(2, (clone $query)->limit(2)->all());
        $this->assertSame($this->_weeklyKeys(4)[0], $query->one()?->key);
    }

    public function testQueryFiltersByDate(): void
    {
        $this->_saveEntry('Bible study');

        $occurrences = (new OccurrenceQuery())
            ->section('events')
            ->from($this->start->modify('+1 day'))
            ->to($this->start->modify('+15 days'))
            ->all();

        $this->assertSame(array_slice($this->_weeklyKeys(4), 1, 2), array_map(fn(Occurrence $o): string => $o->key, $occurrences));
    }

    public function testUniqueReturnsEachEntrysNextDate(): void
    {
        $this->_saveEntry('Bible study');
        $this->_saveEntry('Choir', $this->start->modify('+1 day'));

        $this->assertCount(2, (new OccurrenceQuery())->section('events')->unique()->all());
    }

    public function testCancelledDatesAreLeftOutUnlessIncluded(): void
    {
        $cancelled = $this->_weeklyKeys(4)[1];
        $this->_saveEntry('Bible study', null, ['overrides' => [$cancelled => ['cancelled' => true]]]);

        $this->assertSame(3, (new OccurrenceQuery())->section('events')->count());
        $this->assertSame(4, (new OccurrenceQuery())->section('events')->includeCancelled()->count());
    }

    public function testOccursBetweenFiltersEntries(): void
    {
        $study = $this->_saveEntry('Bible study');
        $this->_saveEntry('Choir', $this->start->modify('+2 months'));

        $ids = Entry::find()
            ->section('events')
            ->occursBetween($this->start, $this->start->modify('+1 week'))
            ->ids();

        $this->assertSame([$study->id], $ids);
    }

    public function testOccurrenceUrlsAddTheDate(): void
    {
        $this->_saveEntry('Bible study');
        $occurrence = (new OccurrenceQuery())->section('events')->one();

        $this->assertSame(UrlHelper::siteUrl('events/bible-study/' . $this->_weeklyKeys(4)[0]), $occurrence->getUrl());
    }

    /**
     * Saves an `events` entry with a weekly, four-date, 90-minute schedule
     * from `$start` (default: the test's start), adjusted by `$schedule`.
     */
    private function _saveEntry(string $title, ?DateTimeImmutable $start = null, array $schedule = []): Entry
    {
        $entry = new Entry([
            'sectionId' => $this->section->id,
            'typeId' => $this->entryType->id,
            'title' => $title,
            'slug' => StringHelper::toKebabCase($title),
        ]);
        $entry->setFieldValue('eventSchedule', $this->_scheduleData($start ?? $this->start, $schedule));
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), implode(' ', $entry->getFirstErrors()));

        return $entry;
    }

    private function _scheduleData(DateTimeImmutable $start, array $overrides = []): array
    {
        return $overrides + [
            'tz' => self::TZ,
            'start' => $this->_local($start),
            'end' => $this->_local($start->modify('+90 minutes')),
            'freq' => 'weekly',
            'ends' => 'count',
            'count' => 4,
        ];
    }

    /** The keys of `$count` weekly dates from the default start. */
    private function _weeklyKeys(int $count): array
    {
        return array_map(fn(int $week): string => $this->start->modify("+$week weeks")->format('Y-m-d'), range(0, $count - 1));
    }

    /** The occurrence keys stored for an element, in date order. */
    private function _storedKeys(Entry $entry): array
    {
        return (new Query())
            ->select(['occurrenceKey'])
            ->from([Plugin::TABLE_OCCURRENCES])
            ->where(['elementId' => $entry->id])
            ->orderBy(['startDate' => SORT_ASC])
            ->column();
    }

    private function _local(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d\TH:i');
    }
}
