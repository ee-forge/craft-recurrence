<?php

namespace eeforge\recurrence\migrations;

use craft\db\Migration;
use craft\db\Table;
use eeforge\recurrence\Plugin;

/**
 * Creates the occurrences table. It's derived data: everything in it can
 * be rebuilt from the field values with `craft recurrence/occurrences/rebuild`.
 */
class Install extends Migration
{
    /**
     * Creates the occurrences table, its database indexes and foreign keys. Run by
     * Craft when the plugin is installed.
     */
    public function safeUp(): bool
    {
        $table = Plugin::TABLE_OCCURRENCES;

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'fieldId' => $this->integer()->notNull(),
            // Original key (overrides, ICS) and actual-date key (URLs); see models\Occurrence.
            'occurrenceKey' => $this->string(15)->notNull(),
            'urlKey' => $this->string(15)->notNull(),
            'startDate' => $this->dateTime()->notNull(),
            'endDate' => $this->dateTime()->notNull(),
            'originalStartDate' => $this->dateTime()->notNull(),
            'allDay' => $this->boolean()->notNull()->defaultValue(false),
            'cancelled' => $this->boolean()->notNull()->defaultValue(false),
            'rescheduled' => $this->boolean()->notNull()->defaultValue(false),
            'note' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Listings filter by site and a start/end range (OccurrenceQuery,
        // occursBetween()); the unique key guards against duplicate rows for
        // one date of one element, site and field.
        $this->createIndex(null, $table, ['siteId', 'startDate']);
        $this->createIndex(null, $table, ['siteId', 'endDate']);
        $this->createIndex(null, $table, ['elementId', 'siteId', 'fieldId', 'occurrenceKey'], true);

        // Hard-deleting an element, site or field removes its occurrences.
        // Soft-deleted elements are excluded from queries by the element
        // subquery; restoring one rebuilds its rows.
        $this->addForeignKey(null, $table, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, $table, ['siteId'], Table::SITES, ['id'], 'CASCADE');
        $this->addForeignKey(null, $table, ['fieldId'], Table::FIELDS, ['id'], 'CASCADE');

        return true;
    }

    /** Drops the occurrences table when the plugin is uninstalled. Field values are untouched. */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Plugin::TABLE_OCCURRENCES);
        return true;
    }
}
