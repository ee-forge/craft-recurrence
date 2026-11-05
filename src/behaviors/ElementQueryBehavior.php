<?php

namespace eeforge\recurrence\behaviors;

use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\events\CancelableEvent;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTimeInterface;
use eeforge\recurrence\Plugin;
use yii\base\Behavior;
use yii\base\InvalidArgumentException;

/**
 * Adds `occursBetween()` to element queries:
 *
 * {% set thisWeek = craft.entries.section('events').occursBetween(now, now|date_modify('+7 days')).all() %}
 *
 * It matches elements with at least one non-canceled occurrence overlapping
 * the range; either bound may be null. Matching runs against the
 * occurrences table, so only saved (canonical) elements match, not drafts.
 *
 * Attached to every element query by Plugin::init().
 *
 * @property ElementQuery $owner
 */
class ElementQueryBehavior extends Behavior
{
    public ?DateTimeInterface $occursFrom = null;
    public ?DateTimeInterface $occursTo = null;

    /** Whether occursBetween() was called; with both bounds null it still requires a date. */
    private bool $occursFilter = false;

    /** Hooks into query preparation, where the subquery can be extended. */
    public function events(): array
    {
        return [
            ElementQuery::EVENT_BEFORE_PREPARE => 'beforePrepare',
        ];
    }

    /**
     * Limits the query to elements with a non-canceled date overlapping
     * `$from`–`$to`. Dates are anything Craft's date params accept; strings
     * without a zone mean site-local time. Returns the query for chaining.
     */
    public function occursBetween(mixed $from = null, mixed $to = null): ElementQuery
    {
        $this->occursFilter = true;
        $this->occursFrom = $this->toDate($from);
        $this->occursTo = $this->toDate($to);
        return $this->owner;
    }

    /**
     * Adds the date condition when the query is prepared. It goes on the
     * element subquery, where the elements and sites tables are joined, as an
     * EXISTS so each element appears once however many dates match.
     */
    public function beforePrepare(CancelableEvent $event): void
    {
        if (!$this->occursFilter || !$this->owner->subQuery) {
            return;
        }

        // Rows for this element on this site, skipping canceled dates.
        $exists = (new Query())
            ->from(['occurrences' => Plugin::TABLE_OCCURRENCES])
            ->where('[[occurrences.elementId]] = [[elements.id]]')
            ->andWhere('[[occurrences.siteId]] = [[elements_sites.siteId]]')
            ->andWhere(['occurrences.cancelled' => false]);

        // Overlap test: the date ends after the range starts, and starts before
        // it ends. The occurrences table stores UTC, as prepareDateForDb() converts to.
        if ($this->occursFrom) {
            $exists->andWhere(['>=', 'occurrences.endDate', Db::prepareDateForDb($this->occursFrom)]);
        }

        if ($this->occursTo) {
            $exists->andWhere(['<', 'occurrences.startDate', Db::prepareDateForDb($this->occursTo)]);
        }

        $this->owner->subQuery->andWhere(['exists', $exists]);
    }

    /** Normalizes an occursBetween() bound; throws on anything unparseable. */
    private function toDate(mixed $value): ?DateTimeInterface
    {
        if ($value === null) {
            return null;
        }

        // Like Craft's own date params, strings without a zone mean site-local time.
        $date = DateTimeHelper::toDateTime($value, true);

        if (!$date) {
            throw new InvalidArgumentException('Invalid date passed to occursBetween().');
        }

        return $date;
    }
}
