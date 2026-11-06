<?php

namespace eeforge\recurrence\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use DateTimeImmutable;
use eeforge\recurrence\fields\Recurrence;
use eeforge\recurrence\models\Schedule;
use eeforge\recurrence\Plugin;
use yii\base\Component;

/**
 * Keeps the occurrences table in step with element saves.
 *
 * Each (element, site, field) is rewritten wholesale on save; the window runs
 * from `pastMonths` ago to `horizonMonths` ahead. `extendAll()` is run daily so
 * open-ended rules keep reaching the horizon.
 *
 * The table is derived data: every row can be rebuilt from the field values.
 * It exists so listings, paging and counting across entries are plain SQL
 * (see OccurrenceQuery and ElementQueryBehavior). Drafts and revisions are
 * never stored; they're expanded on the fly instead.
 *
 * Registered as the plugin's `occurrences` component; reach it with
 * `Plugin::getInstance()->getOccurrences()`.
 */
class Occurrences extends Component
{
    /**
     * Rewrites one element's rows for its current site: delete, then insert
     * the dates in the occurrences table window, in one transaction.
     *
     * Called by the field after an element is saved or restored, and by
     * extendAll()/rebuildAll() for bulk work.
     *
     * @param Recurrence $field The layout's instance of the field (its handle may differ from the global one)
     * @return int Number of rows written
     */
    public function rebuild(ElementInterface $element, Recurrence $field): int
    {
        // Only saved, canonical elements are stored; drafts expand on the fly.
        if (!$element->id || ElementHelper::isDraftOrRevision($element)) {
            return 0;
        }

        $value = $element->getFieldValue($field->handle);
        $rows = [];

        // Expand the schedule over the table's window (pastMonths ago to the
        // horizon) into one row per date. An empty field writes no rows,
        // which clears the element's dates below.
        if ($value instanceof Schedule && !$value->isEmpty()) {
            $now = new DateTimeImmutable();
            $timestamp = Db::prepareDateForDb($now);

            foreach ($value->occurrencesTableDates($now) as $occurrence) {
                $rows[] = [
                    'elementId' => $element->id,
                    'siteId' => $element->siteId,
                    'fieldId' => $field->id,
                    'occurrenceKey' => $occurrence->key,
                    'urlKey' => $occurrence->urlKey,
                    'startDate' => Db::prepareDateForDb($occurrence->start),
                    'endDate' => Db::prepareDateForDb($occurrence->end),
                    'originalStartDate' => Db::prepareDateForDb($occurrence->originalStart),
                    'allDay' => $occurrence->allDay,
                    'cancelled' => $occurrence->cancelled,
                    'rescheduled' => $occurrence->rescheduled,
                    'note' => $occurrence->note,
                    'dateCreated' => $timestamp,
                    'dateUpdated' => $timestamp,
                    'uid' => StringHelper::UUID(),
                ];
            }
        }

        // Replace the element's rows atomically, so listings never see it
        // half-written or briefly without dates.
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();
        try {
            Db::delete(Plugin::TABLE_OCCURRENCES, [
                'elementId' => $element->id,
                'siteId' => $element->siteId,
                'fieldId' => $field->id,
            ]);
            if ($rows) {
                Db::batchInsert(Plugin::TABLE_OCCURRENCES, array_keys($rows[0]), $rows);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return count($rows);
    }

    /**
     * Rebuilds every element already in the occurrences table. Run daily (cron) so
     * open-ended rules extend and old rows fall out of the window. Called by
     * `craft recurrence/occurrences/extend`.
     *
     * Starts from the table, not from entries, which keeps the daily run cheap
     * but means entries with no rows are never revisited; rebuildAll() covers
     * those.
     *
     * @param int|null $fieldId Limit to one field
     * @return int Number of elements rebuilt
     */
    public function extendAll(?callable $progress = null, ?int $fieldId = null): int
    {
        // Every (element, site, field) that has rows.
        $pairs = (new Query())
            ->select(['elementId', 'siteId', 'fieldId'])
            ->distinct()
            ->from(Plugin::TABLE_OCCURRENCES)
            ->filterWhere(['fieldId' => $fieldId])
            ->all();

        $count = 0;

        // Reload each one, any status, and rewrite its rows with today's window.
        foreach ($pairs as $pair) {
            $element = Craft::$app->getElements()->getElementById((int)$pair['elementId'], null, (int)$pair['siteId'], [
                'status' => null,
            ]);

            // Use the layout's instance of the field: its handle may differ from the global one.
            $field = $element?->getFieldLayout()?->getFieldById((int)$pair['fieldId']);

            if (!$field instanceof Recurrence) {
                // The element is gone or the field was removed from its layout.
                Db::delete(Plugin::TABLE_OCCURRENCES, $pair);
                continue;
            }

            $this->rebuild($element, $field);
            $count++;

            if ($progress) {
                $progress($element, $count, count($pairs));
            }
        }
        return $count;
    }

    /**
     * Rebuilds all elements of a type whose layouts include a Recurrence field,
     * across all sites, regardless of whether they're in the occurrences table yet. Used by the
     * RebuildOccurrences job (after a horizon, past-months or max-dates change) and by
     * `craft recurrence/occurrences/rebuild`.
     *
     * @param class-string<ElementInterface> $elementType
     * @param callable|null $progress Called after each element as `($element, $done, $total)`
     *
     * @param array $criteria Element query criteria, e.g. `['section' => 'events']`
     * @return int Number of element/site pairs rebuilt
     */
    public function rebuildAll(string $elementType, array $criteria = [], ?callable $progress = null): int
    {
        // Every element of the type, on every site, any status.
        /** @var class-string<ElementInterface> $elementType */
        $query = $elementType::find()->site('*')->status(null);

        if ($elementType === Entry::class) {
            // Only entry types whose layout has the field, not every entry on the site.
            $typeIds = [];

            foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
                foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
                    if ($field instanceof Recurrence) {
                        $typeIds[] = $entryType->id;
                        break;
                    }
                }
            }

            if (!$typeIds) {
                return 0;
            }

            $query->typeId($typeIds);
        }

        // Caller's narrowing, e.g. `--section=events`.
        Craft::configure($query, $criteria);

        $total = $progress ? (int)$query->count() : 0;
        $done = 0;
        $count = 0;

        // Batch through them (Db::each keeps memory flat) and rebuild those
        // whose own layout has the field.
        foreach (Db::each($query) as $element) {
            $done++;

            if ($field = Plugin::getInstance()->recurrenceField($element)) {
                $this->rebuild($element, $field);
                $count++;
                if ($progress) {
                    $progress($element, $done, $total);
                }
            }
        }
        return $count;
    }
}
