<?php

namespace eeforge\recurrence\queries;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTimeImmutable;
use DateTimeInterface;
use eeforge\recurrence\fields\Recurrence;
use eeforge\recurrence\models\Occurrence;
use eeforge\recurrence\Plugin;
use yii\base\InvalidArgumentException;

/**
 * Queries the occurrences table across elements.
 *
 * {% set occurrences = craft.recurrence.occurrences()
 *     .section('events')
 *     .from(now)
 *     .with(['generalImage'])
 *     .limit(12)
 *     .all() %}
 *
 * Element criteria (section, type, relatedTo, search, any other element query
 * param) go into an element query that becomes an id subquery, so status,
 * post/expiry dates, site and permissions behave exactly as in a normal
 * element query. The occurrence rows are then paged with plain SQL and their
 * elements eager-loaded in one query.
 *
 * It is a real `craft\db\Query`, so `count()`, `exists()`, `batch()` and the
 * paginators (`{% paginate %}`, `sprig.paginate()`) work on it; rows come
 * back as `Occurrence` models. `prepare()` builds the SQL afresh each time,
 * which is why `from()` can mean a date here rather than a table.
 *
 * Created by `craft.recurrence.occurrences()` (RecurrenceVariable). The site's
 * events listing is the main user.
 *
 * @method static section(mixed $value)
 * @method static type(mixed $value)
 * @method static relatedTo(mixed $value)
 * @method static search(mixed $value)
 * @method static status(mixed $value)
 * @method static id(mixed $value)
 */
class OccurrenceQuery extends Query
{
    /** @var class-string<ElementInterface> */
    public string $elementType = Entry::class;
    /** Element query criteria. */
    public array $criteria = [];
    /** See from(). (Query's own `$from` holds tables and is unused here.) */
    public ?DateTimeInterface $fromDate = null;
    /** See to(). */
    public ?DateTimeInterface $toDate = null;
    /** Null means the current site. */
    public ?int $siteId = null;
    /** Restrict to one Recurrence field, by handle. */
    public ?string $field = null;
    /** Canceled dates are included by default, so listings can label them. */
    public bool $includeCancelled = true;
    /** Eager-loading for the elements, as in element queries. */
    public array|string|null $with = null;
    /** Latest first instead of soonest first. */
    public bool $reverse = false;
    /** One occurrence per element: its next (or, reversed, latest) matching date. */
    public bool $unique = false;

    /** Unknown methods become element query criteria, e.g. `.authorId(1)`. */
    public function __call($name, $params)
    {
        $this->criteria[$name] = $params[0] ?? true;
        return $this;
    }

    /** Element type to query; entries by default. */
    public function elementType(string $elementType): static
    {
        if (!is_subclass_of($elementType, ElementInterface::class)) {
            throw new InvalidArgumentException("$elementType is not an element type.");
        }
        $this->elementType = $elementType;
        return $this;
    }

    /** Element query criteria to merge in, e.g. `['section' => 'events']`. */
    public function criteria(array $criteria): static
    {
        $this->criteria = array_merge($this->criteria, $criteria);
        return $this;
    }

    /**
     * Occurrences that haven't ended by this time (ongoing ones included).
     * Overrides Query::from(), which would name tables; the table is fixed.
     */
    public function from($value): static
    {
        $this->fromDate = $this->parseDate($value);
        return $this;
    }

    /** Occurrences that start before this time. */
    public function to(mixed $value): static
    {
        $this->toDate = $this->parseDate($value);
        return $this;
    }

    /** Site by handle or ID. Occurrences and elements both come from this site. */
    public function site(mixed $value): static
    {
        if (is_numeric($value)) {
            $this->siteId = (int)$value;
        } else {
            $site = Craft::$app->getSites()->getSiteByHandle((string)$value);
            if (!$site) {
                throw new InvalidArgumentException("Invalid site handle: $value");
            }
            $this->siteId = $site->id;
        }
        return $this;
    }

    /** Site by ID; null for the current site. */
    public function siteId(?int $value): static
    {
        $this->siteId = $value;
        return $this;
    }

    /** Limits to one Recurrence field by handle. Rarely needed, since layouts have one. */
    public function field(?string $handle): static
    {
        $this->field = $handle;
        return $this;
    }

    /** Pass false to leave out canceled dates. */
    public function includeCancelled(bool $value = true): static
    {
        $this->includeCancelled = $value;
        return $this;
    }

    /** Relations to eager-load on the elements, e.g. `['generalImage']`. */
    public function with(array|string|null $value): static
    {
        $this->with = $value;
        return $this;
    }

    /** Latest first, e.g. for a "past events" archive. */
    public function reverse(bool $value = true): static
    {
        $this->reverse = $value;
        return $this;
    }

    /**
     * One result per event rather than per date, e.g. a weekly event listed once
     * at its next date.
     */
    public function unique(bool $value = true): static
    {
        $this->unique = $value;
        return $this;
    }

    /**
     * The SQL for QueryBuilder: the filtered occurrence table, with this
     * query's select (Query's count/exists swap it), paging, and any extra
     * where conditions. Ordered only when fetching rows.
     *
     * Craft's QueryBuilder calls this for every execution (all(), count(),
     * exists(), paginators), so filters set after creation always apply.
     */
    public function prepare($builder): Query
    {
        // The filtered, ordered table, then this query's select and paging.
        $query = $this->buildQuery()
            ->select($this->select ?? ['o.*'])
            ->limit($this->limit)
            ->offset($this->offset)
            ->addParams($this->params);

        // count()/exists() set a select; ordering is wasted work for them.
        if ($this->select !== null) {
            $query->orderBy(null);
        }
        // Any where() the caller added on top.
        if ($this->where) {
            $query->andWhere($this->where);
        }
        return $query;
    }

    /**
     * Turns rows into Occurrence models, eager-loading their elements in one
     * query. Rows whose element isn't found are dropped. Called by all()
     * (and so by one() and the paginators) once the rows are fetched.
     *
     * @return Occurrence[]
     */
    public function populate($rows): array
    {
        if (!$rows) {
            return [];
        }

        // Every element the page needs, in one query, with eager-loading.
        // Status is already filtered by the id subquery, so none here.
        $elementIds = array_values(array_unique(array_map(fn($row) => (int)$row['elementId'], $rows)));
        $elements = ($this->elementType)::find()
            ->id($elementIds)
            ->siteId($this->resolveSiteId())
            ->status(null)
            ->with($this->with)
            ->indexBy('id')
            ->all();

        // One model per row, in row order, with dates back from UTC.
        $occurrences = [];
        foreach ($rows as $row) {
            $element = $elements[$row['elementId']] ?? null;
            if (!$element) {
                continue;
            }
            $occurrences[] = new Occurrence(
                start: $this->rowDate($row['startDate']),
                end: $this->rowDate($row['endDate']),
                originalStart: $this->rowDate($row['originalStartDate']),
                key: $row['occurrenceKey'],
                allDay: (bool)$row['allDay'],
                cancelled: (bool)$row['cancelled'],
                note: $row['note'],
                rescheduled: (bool)$row['rescheduled'],
                element: $element,
                urlKey: $row['urlKey'],
            );
        }
        return $occurrences;
    }

    /**
     * Total matching occurrences, ignoring limit and offset. Always an int:
     * Query returns false when the element criteria can't match anything.
     */
    public function count($q = '*', $db = null): int
    {
        return (int)parent::count($q, $db);
    }

    /**
     * The first matching occurrence. Query::one() would return a raw row,
     * so this goes through all() and populate() instead.
     */
    public function one($db = null): ?Occurrence
    {
        $limit = $this->limit;
        $this->limit = 1;
        try {
            return $this->all($db)[0] ?? null;
        } finally {
            $this->limit = $limit;
        }
    }

    /** The SQL query over the occurrence table, ordered, without paging. Used by prepare(). */
    private function buildQuery(): Query
    {
        $siteId = $this->resolveSiteId();

        // The element criteria as an id subquery: Craft applies status,
        // post/expiry dates and the site exactly as a normal query would.
        $elementQuery = ($this->elementType)::find();
        Craft::configure($elementQuery, $this->criteria);
        $elementQuery->siteId($siteId)->select(['elements.id']);

        // Occurrences of those elements, filtered by date etc., soonest first
        // (latest first when reversed); the id makes the order stable.
        $query = $this->filtered('o', $siteId, $elementQuery)
            ->orderBy($this->reverse
                ? ['o.startDate' => SORT_DESC, 'o.id' => SORT_DESC]
                : ['o.startDate' => SORT_ASC, 'o.id' => SORT_ASC]);

        if ($this->unique) {
            // unique(): keep only each element's first matching occurrence
            // (last, when reversed). A grouped subquery finds that date per
            // element, and joining on it drops every other row. It uses the
            // same filters, so "first" means first within the range.
            $first = $this->filtered('u', $siteId, $elementQuery)
                ->select([
                    'u.elementId',
                    'u.fieldId',
                    'startDate' => $this->reverse ? 'MAX([[u.startDate]])' : 'MIN([[u.startDate]])',
                ])
                ->groupBy(['u.elementId', 'u.fieldId']);
            $query->innerJoin(
                ['first' => $first],
                '[[first.elementId]] = [[o.elementId]] AND [[first.fieldId]] = [[o.fieldId]] AND [[first.startDate]] = [[o.startDate]]',
            );
        }

        return $query;
    }

    /**
     * The occurrence table under an alias, with every filter but ordering
     * applied. Built twice by buildQuery() when unique() is on, for the main
     * query and its grouped subquery.
     */
    private function filtered(string $alias, int $siteId, mixed $elementQuery): Query
    {
        $query = (new Query())
            ->from([$alias => Plugin::TABLE_OCCURRENCES])
            ->where(["$alias.siteId" => $siteId])
            ->andWhere(["$alias.elementId" => $elementQuery]);

        // Overlap with the range: ends after `from`, starts before `to`. The
        // occurrences table stores UTC, as prepareDateForDb() converts to.
        if ($this->fromDate) {
            $query->andWhere(['>=', "$alias.endDate", Db::prepareDateForDb($this->fromDate)]);
        }
        if ($this->toDate) {
            $query->andWhere(['<', "$alias.startDate", Db::prepareDateForDb($this->toDate)]);
        }
        if (!$this->includeCancelled) {
            $query->andWhere(["$alias.cancelled" => false]);
        }
        if ($this->field !== null) {
            $field = Craft::$app->getFields()->getFieldByHandle($this->field);
            if (!$field instanceof Recurrence) {
                throw new InvalidArgumentException("“{$this->field}” is not a Recurrence field.");
            }
            $query->andWhere(["$alias.fieldId" => $field->id]);
        }

        return $query;
    }

    /** The site to query: the one set, else the current site. */
    private function resolveSiteId(): int
    {
        return $this->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
    }

    /** Normalises a from()/to() value; throws on anything unparseable. */
    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        // Like Craft's own date params, strings without a zone mean site-local time.
        $dt = DateTimeHelper::toDateTime($value, true);
        if (!$dt) {
            throw new InvalidArgumentException('Invalid date: ' . (is_scalar($value) ? $value : get_debug_type($value)));
        }
        return DateTimeImmutable::createFromInterface($dt);
    }

    /** A date column from an occurrences table row, as a DateTimeImmutable. Used by populate(). */
    private function rowDate(string $value): DateTimeImmutable
    {
        // Stored in UTC; returned in the system timezone like Craft's own dates.
        return DateTimeImmutable::createFromInterface(DateTimeHelper::toDateTime($value));
    }
}
