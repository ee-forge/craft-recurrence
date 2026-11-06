<?php

namespace eeforge\recurrence\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use DateTimeImmutable;
use DateTimeZone;
use eeforge\recurrence\models\Schedule;
use eeforge\recurrence\Plugin;
use eeforge\recurrence\services\Expander;
use eeforge\recurrence\web\assets\input\InputAsset;
use RRule\RRule;
use Throwable;
use yii\db\Schema;

/**
 * A start/end date with an optional recurrence rule, extra dates, excluded
 * dates and per-occurrence overrides. Its defaults are plugin settings,
 * shared by every Recurrence field. New schedules take
 * Craft's system time zone and keep it, so DST and ICS stay correct even if
 * the system time zone later changes.
 *
 * Saving a canonical element writes its occurrences to the occurrences table (see
 * services\Occurrences); drafts and revisions are expanded on the fly instead.
 *
 * Registered by Plugin::init(). Value lifecycle: normalizeValue() turns stored
 * JSON (or posted/imported data) into a Schedule; serializeValue() stores its
 * toArray() form; afterElementSave() keeps the occurrences table in step.
 */
class Recurrence extends Field
{
    /** Dates shown in the field's preview list; "Show more" adds this many again. */
    public const PREVIEW_LIMIT = 12;

    /** Length given to new values, in minutes, until an end is chosen. */
    public const DEFAULT_DURATION = 60;

    /** Name in the field type list. */
    public static function displayName(): string
    {
        return Craft::t('recurrence', 'Recurrence');
    }

    /** Icon in the field type list and layout designer. */
    public static function icon(): string
    {
        return 'calendar-days';
    }

    /** The occurrences table holds one schedule per element and field, so a layout can't add the field twice. */
    public static function isMultiInstance(): bool
    {
        return false;
    }

    /** The field's value is a Schedule, or null when empty. */
    public static function phpType(): string
    {
        return sprintf('\\%s|null', Schedule::class);
    }

    /** Stored as the Schedule's JSON; the occurrences table is derived from it. */
    public static function dbType(): string
    {
        return Schema::TYPE_JSON;
    }

    /** The field has no settings of its own; point admins to the plugin settings. */
    public function getSettingsHtml(): ?string
    {
        $url = UrlHelper::cpUrl('settings/plugins/recurrence');
        return Html::tag('p', Craft::t('recurrence', 'How far ahead dates are stored for listings and what calendar files contain are set in the <a href="{url}">Recurrence plugin settings</a>.', [
            'url' => $url,
        ]), ['class' => 'light']);
    }

    /**
     * Zone given to new values: Craft's system time zone. Saved values keep the
     * zone stored with them. Used when normalizing, importing and rendering.
     */
    public function getDefaultTimeZone(): string
    {
        return Craft::$app->getTimeZone();
    }

    /**
     * Accepts a Schedule, an array (posted or stored), a JSON string, or an
     * RFC 5545 rule string (for importers), and returns a Schedule or null.
     * Craft calls this whenever the field's value is read or set; the
     * PreviewController calls it on unsaved CP input.
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        // Already a Schedule (e.g. set in PHP): just attach element and limits.
        if ($value instanceof Schedule) {
            return $this->configure($value, $element);
        }

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        // Strings are the stored/posted JSON, or else an iCal rule from an importer.
        if (is_string($value)) {
            $decoded = Json::decodeIfJson($value);
            $value = is_array($decoded) ? $decoded : $this->fromRfcString($value);
        }

        if (!is_array($value)) {
            return null;
        }

        // Build and sanitize; a value without a start counts as empty.
        $schedule = Schedule::fromArray($value, $this->getDefaultTimeZone());
        return $schedule->isEmpty() ? null : $this->configure($schedule, $element);
    }

    /** What's stored in the content column: the Schedule's array form (saved as JSON), or null when empty. */
    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        return $value instanceof Schedule && !$value->isEmpty() ? $value->toArray() : null;
    }

    /** For Craft's "required" check and `:empty:` queries: no start date means no value. */
    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return !$value instanceof Schedule || $value->isEmpty();
    }

    /**
     * The rule builder. The JS (web/assets/input) edits a hidden JSON input,
     * which is what gets posted, and asks PreviewController to render the
     * upcoming dates list as the rule changes. Craft calls this to render the
     * field on edit pages.
     */
    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        // The input's JS and CSS.
        $view = Craft::$app->getView();
        $view->registerAssetBundle(InputAsset::class);

        $id = $this->getInputId();
        $tz = $value instanceof Schedule ? $value->tz : $this->getDefaultTimeZone();

        // Everything the JS needs: the namespaced input id, the current value,
        // its zone, and how to reach the preview endpoint.
        $config = [
            'id' => $view->namespaceInputId($id),
            'value' => $value instanceof Schedule ? $value->toArray() : null,
            'tz' => $tz,
            'defaultDuration' => self::DEFAULT_DURATION,
            'fieldId' => $this->id,
            'previewAction' => 'recurrence/preview/render',
            'previewLimit' => self::PREVIEW_LIMIT,
        ];

        $view->registerJsWithVars(
            fn($config) => "new Craft.RecurrenceInput($config);",
            [$config],
        );

        // The markup the JS takes over, including the hidden JSON input.
        return $view->renderTemplate('recurrence/_field/input.twig', [
            'id' => $id,
            'name' => $this->handle,
            'json' => $value instanceof Schedule ? Json::encode($value) : '',
        ]);
    }

    /** Read-only view (revisions, no edit permission): the summary and next dates, via preview.twig. */
    public function getStaticHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof Schedule) {
            return '';
        }

        return Craft::$app->getView()->renderTemplate('recurrence/_field/preview.twig', [
            'schedule' => $value,
            // Editors see cancelled dates too, labelled as such.
            'occurrences' => $value->upcoming(self::PREVIEW_LIMIT, true),
            'static' => true,
        ]);
    }

    /** Element index column: the summary, e.g. "Weekly on Wed at 7:00 PM". */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        return $value instanceof Schedule ? Html::encode($value->getSummary()) : '';
    }

    /** Dates and rules make poor search keywords, so none are indexed. */
    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        return '';
    }

    /**
     * Runs validationErrors() when the element is saved, plus the
     * one-field-per-layout backstop. Craft collects these rules from every
     * field in the element's layout.
     */
    public function getElementValidationRules(): array
    {
        return [
            [
                function(ElementInterface $element) {
                    $value = $element->getFieldValue($this->handle);
                    if (!$value instanceof Schedule) {
                        return;
                    }
                    // Backstop for layouts that skipped validation: only the first
                    // Recurrence field is stored for listings, routed and exported.
                    if (!$this->isPrimaryIn($element)) {
                        $element->addError("field:$this->handle", Craft::t('recurrence', 'Only one Recurrence field per layout is supported; remove “{name}” from this layout.', [
                            'name' => $this->name,
                        ]));
                        return;
                    }

                    foreach ($this->validationErrors($value) as $error) {
                        $element->addError("field:$this->handle", $error);
                    }
                },
            ],
        ];
    }

    /**
     * Problems with a schedule, as messages. Shared by element validation and
     * the CP preview, so editors see errors before saving.
     *
     * @return string[]
     */
    public function validationErrors(Schedule $schedule): array
    {
        // Checks, in order: a start; the end after it; a usable `until` or
        // `count` for rules that need one; no reschedules landing on another
        // date's start (they'd share a URL); and override ends after their starts.
        $errors = [];
        $start = $schedule->getStartLocal();
        $end = $schedule->parseLocal($schedule->end, true);

        if (!$start) {
            $errors[] = Craft::t('recurrence', 'A start date is required.');
            return $errors;
        }

        if ($end && $end < $start) {
            $errors[] = Craft::t('recurrence', 'The end must be after the start.');
        }

        if ($schedule->hasRule() && $schedule->ends === Schedule::ENDS_UNTIL) {
            $until = $schedule->parseLocal($schedule->until, true);
            if (!$until) {
                $errors[] = Craft::t('recurrence', 'Choose the date the series ends on.');
            } elseif ($until < $start) {
                $errors[] = Craft::t('recurrence', 'The series can’t end before it starts.');
            }
        }

        if ($schedule->hasRule() && $schedule->ends === Schedule::ENDS_COUNT && !$schedule->count) {
            $errors[] = Craft::t('recurrence', 'Enter how many times the event repeats.');
        }

        foreach (Expander::collisions($schedule) as $key) {
            $errors[] = Craft::t('recurrence', '{date} is moved to the same time as another date in the series.', ['date' => $key]);
        }

        foreach ($schedule->overrides as $key => $override) {
            $newStart = $schedule->parseLocal($override['start'] ?? null, false);
            $newEnd = $schedule->parseLocal($override['end'] ?? null, true);
            if ($newStart && $newEnd && $newEnd < $newStart) {
                $errors[] = Craft::t('recurrence', 'The rescheduled end for {date} must be after its start.', ['date' => $key]);
            }
        }

        return $errors;
    }

    /** Rewrites the element's occurrences table rows after Craft saves it. */
    public function afterElementSave(ElementInterface $element, bool $isNew): void
    {
        // Drafts and revisions never reach the occurrences table; templates previewing
        // them expand the schedule on the fly.
        if (!ElementHelper::isDraftOrRevision($element) && $this->isPrimaryIn($element)) {
            Plugin::getInstance()->getOccurrences()->rebuild($element, $this);
        }
        parent::afterElementSave($element, $isNew);
    }

    /** Rewrites the element's occurrences table rows after it's restored from the trash. */
    public function afterElementRestore(ElementInterface $element): void
    {
        // extendAll() drops rows of trashed elements, so restore them here.
        if (!ElementHelper::isDraftOrRevision($element) && $this->isPrimaryIn($element)) {
            Plugin::getInstance()->getOccurrences()->rebuild($element, $this);
        }
        parent::afterElementRestore($element);
    }

    /**
     * Whether this is the element's primary Recurrence field (the first in its
     * layout), the one whose dates are stored. Guards the occurrences table
     * and validation against a second field that slipped into a layout.
     */
    private function isPrimaryIn(ElementInterface $element): bool
    {
        return Plugin::getInstance()->recurrenceField($element)?->id === $this->id;
    }

    /**
     * Attaches the element (for occurrence URLs) and the plugin's expansion
     * limits, which are settings rather than part of the stored value. Called
     * by normalizeValue() on every value it returns.
     */
    private function configure(Schedule $schedule, ?ElementInterface $element): Schedule
    {
        $settings = Plugin::getInstance()->getSettings();
        $schedule->element = $element;
        return $schedule->withLimits($settings->horizonMonths, $settings->pastMonths, $settings->maxOccurrences);
    }

    /**
     * Accepts an RFC 5545 string such as
     * `DTSTART;TZID=America/New_York:20261105T190000\nRRULE:FREQ=WEEKLY;BYDAY=TH`,
     * so importers (e.g. Feed Me) can map an iCal rule straight into the field.
     * Returns fromArray() data, or null when the string isn't a usable rule.
     */
    private function fromRfcString(string $value): ?array
    {
        // Cheap check first: it must at least look like iCal.
        if (!str_contains(strtoupper($value), 'FREQ=') && !str_contains(strtoupper($value), 'DTSTART')) {
            return null;
        }

        // Let php-rrule parse it; anything it rejects is not a value.
        try {
            $parts = (new RRule(trim($value)))->getRule();
        } catch (Throwable) {
            return null;
        }

        if (empty($parts['DTSTART'])) {
            return null;
        }

        // Use DTSTART's own zone when it's a real IANA zone (a TZID);
        // UTC or floating times are read in the system zone.
        $tz = $this->getDefaultTimeZone();
        $start = DateTimeImmutable::createFromInterface($parts['DTSTART']);

        if ($start->getTimezone()->getName() !== 'UTC' && in_array($start->getTimezone()->getName(), DateTimeZone::listIdentifiers(), true)) {
            $tz = $start->getTimezone()->getName();
        }
        $start = $start->setTimezone(new DateTimeZone($tz));

        // A rule carries no end time, so new values get DEFAULT_DURATION.
        $data = [
            'tz' => $tz,
            'start' => $start->format(Schedule::FORMAT),
            'end' => $start->modify('+' . self::DEFAULT_DURATION . ' minutes')->format(Schedule::FORMAT),
            'freq' => strtolower((string)($parts['FREQ'] ?? Schedule::FREQ_NONE)),
            'interval' => (int)($parts['INTERVAL'] ?? 1) ?: 1,
        ];

        // Map BYDAY to weekdays (weekly) or the monthly mode ("-1FR" = last Friday).
        $byDay = array_filter(explode(',', (string)($parts['BYDAY'] ?? '')));
        if ($data['freq'] === Schedule::FREQ_WEEKLY) {
            $data['byDay'] = $byDay;
        } elseif ($data['freq'] === Schedule::FREQ_MONTHLY && $byDay) {
            $data['monthlyMode'] = str_starts_with($byDay[0], '-1') ? Schedule::MONTHLY_LAST : Schedule::MONTHLY_NTH;
        }

        // How it ends: UNTIL becomes a local end date, COUNT a count.
        if (!empty($parts['UNTIL'])) {
            $data['ends'] = Schedule::ENDS_UNTIL;
            $data['until'] = DateTimeImmutable::createFromInterface($parts['UNTIL'])->setTimezone(new DateTimeZone($tz))->format(Schedule::FORMAT_DAY);
        } elseif (!empty($parts['COUNT'])) {
            $data['ends'] = Schedule::ENDS_COUNT;
            $data['count'] = (int)$parts['COUNT'];
        }

        return $data;
    }
}
