<?php

namespace eeforge\recurrence\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Address;
use craft\elements\db\ElementQueryInterface;
use craft\fields\Addresses;
use craft\fields\Entries;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use eeforge\recurrence\Plugin;
use Illuminate\Support\Collection;
use Stringable;
use Throwable;

/**
 * Resolves the ICS description and location of an element from the plugin
 * settings: nothing, a field (picked by UID), or a custom object template.
 *
 * resolve() is called by IcsController for each entry in a calendar file;
 * fieldOptions() fills the source selects on the plugin settings page.
 */
class IcsDetails
{
    /** Source setting values; anything else is a field UID. */
    public const SOURCE_NONE = '';
    public const SOURCE_TEMPLATE = 'template';

    /** Which detail is being resolved; decides the allowed field types. */
    public const DESCRIPTION = 'description';
    public const LOCATION = 'location';

    /** Field types offered for each detail. Rich text types are optional plugins. */
    private const FIELD_TYPES = [
        self::DESCRIPTION => [PlainText::class, 'craft\ckeditor\Field'],
        self::LOCATION => [PlainText::class, Addresses::class, Entries::class],
    ];

    /**
     * One detail (description or location) of an element, as plain text for
     * the ICS file, plus map coordinates when the source is an address.
     * Anything that can't be resolved (no source, a field missing from this
     * element's layout, a template error) gives empty text rather than an
     * error, so one bad entry can't break a whole feed.
     *
     * @param string $kind self::DESCRIPTION or self::LOCATION
     * @param string $source the `ics{Kind}Source` setting: blank, `template`, or a field UID
     * @param string $template the `ics{Kind}Template` setting, used when $source is `template`
     * @return array{text: string, geo: array{0: string, 1: string}|null}
     */
    public static function resolve(ElementInterface $element, string $kind, string $source, string $template): array
    {
        $empty = ['text' => '', 'geo' => null];

        // Custom template: render it against the element and flatten the
        // HTML. Errors are logged, not thrown.
        if ($source === self::SOURCE_TEMPLATE) {
            if (trim($template) === '') {
                return $empty;
            }

            try {
                $html = Craft::$app->getView()->renderObjectTemplate($template, $element);
            } catch (Throwable $e) {
                Craft::warning("Couldn't render the ICS $kind template: {$e->getMessage()}", __METHOD__);
                return $empty;
            }

            return ['text' => Ics::htmlToText($html), 'geo' => null];
        }

        // A field: find it in this element's layout (by UID, since layouts
        // can rename it) and check its type still suits the detail.
        $field = $source !== self::SOURCE_NONE ? self::_layoutField($element->getFieldLayout(), $source) : null;
        if (!$field instanceof FieldInterface || !self::_supports($field, $kind)) {
            return $empty;
        }

        // Relation and address fields hold several values; use the first.
        $value = $element->getFieldValue($field->handle);
        if ($value instanceof ElementQueryInterface) {
            $value = $value->one();
        } elseif ($value instanceof Collection || is_array($value)) {
            $value = collect($value)->first();
        }

        // Turn the value into text by kind:
        // - an address: one line, comma-separated, with GEO coordinates;
        // - another element (Entries field): its title;
        // - plain text: as is;
        // - rich text (CKEditor, Redactor): HTML converted to text.
        if ($value instanceof Address) {
            $lines = explode("\n", Craft::$app->getAddresses()->formatAddress($value, ['html' => false]));
            $geo = $value->latitude !== null && $value->longitude !== null ? [$value->latitude, $value->longitude] : null;
            return ['text' => implode(', ', array_filter(array_map(trim(...), $lines))), 'geo' => $geo];
        }

        if ($value instanceof ElementInterface) {
            return ['text' => trim((string)$value->title), 'geo' => null];
        }

        if ($field instanceof PlainText) {
            return ['text' => trim((string)$value), 'geo' => null];
        }

        if (is_string($value) || $value instanceof Stringable) {
            return ['text' => Ics::htmlToText((string)$value), 'geo' => null];
        }

        return $empty;
    }

    /**
     * Select options for a detail: fields of a supported type in layouts that
     * have a Recurrence field, labeled with their layout names. Used by
     * Plugin::settingsHtml() for the description and location selects.
     *
     * @param string $kind self::DESCRIPTION or self::LOCATION
     * @return array<array{label: string, value: string}> sorted by label
     */
    public static function fieldOptions(string $kind): array
    {
        // Collect each field's names across layouts: a field renamed in one
        // layout is listed once, as "Name / Other name".
        $labels = [];
        foreach (Craft::$app->getFields()->getAllLayouts() as $layout) {
            if (!Plugin::recurrenceFields($layout)) {
                continue;
            }

            foreach ($layout->getCustomFields() as $field) {
                if (self::_supports($field, $kind)) {
                    $labels[$field->uid][$field->name] = true;
                }
            }
        }

        $options = [];
        foreach ($labels as $uid => $names) {
            $options[] = ['label' => implode(' / ', array_keys($names)), 'value' => $uid];
        }
        usort($options, fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        return $options;
    }

    /**
     * The layout's instance of a field, which carries the layout's handle
     * (layouts can rename fields), or null if the layout doesn't have it.
     */
    private static function _layoutField(?FieldLayout $layout, string $uid): ?FieldInterface
    {
        foreach ($layout?->getCustomFields() ?? [] as $field) {
            if ($field->uid === $uid) {
                return $field;
            }
        }
        return null;
    }

    /** Whether a field's type can supply a detail, per FIELD_TYPES. */
    private static function _supports(FieldInterface $field, string $kind): bool
    {
        foreach (self::FIELD_TYPES[$kind] as $class) {
            if ($field instanceof $class) {
                return true;
            }
        }
        return false;
    }
}
