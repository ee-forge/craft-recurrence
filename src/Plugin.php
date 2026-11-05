<?php

namespace eeforge\recurrence;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\db\ElementQuery;
use craft\events\ConfigEvent;
use craft\events\DefineBehaviorsEvent;
use craft\events\PluginEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\services\Fields;
use craft\services\Plugins;
use craft\services\ProjectConfig;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use eeforge\recurrence\behaviors\ElementQueryBehavior;
use eeforge\recurrence\fields\Recurrence;
use eeforge\recurrence\helpers\IcsDetails;
use eeforge\recurrence\jobs\RebuildOccurrences;
use eeforge\recurrence\models\Settings;
use eeforge\recurrence\services\Occurrences;
use eeforge\recurrence\variables\RecurrenceVariable;
use yii\base\Event;

/**
 * Recurring dates for native entries.
 *
 * The plugin class wires everything into Craft (see init()): the Recurrence
 * field type, `craft.recurrence` in Twig, `occursBetween()` on element
 * queries, the ICS routes, and the listeners that keep the
 * occurrences table in step with settings changes. The table itself is
 * maintained by the `occurrences` service ({@see Occurrences}).
 *
 * @property-read Occurrences $occurrences
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** The occurrences table: one row per date per element, site and field. */
    public const TABLE_OCCURRENCES = '{{%recurrence_occurrences}}';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    /** Settings that change which dates are in the occurrences table. */
    private const OCCURRENCES_TABLE_SETTINGS = ['horizonMonths', 'pastMonths', 'maxOccurrences'];

    /** One settings save can fire several config events; queue a single rebuild. */
    private bool $rebuildQueued = false;

    /**
     * Component config Craft applies when it creates the plugin, registering
     * the `occurrences` service reached through getOccurrences().
     */
    public static function config(): array
    {
        return [
            'components' => [
                'occurrences' => Occurrences::class,
            ],
        ];
    }

    /**
     * Registers the plugin's event handlers. Craft calls this once per
     * request, after loading the plugin.
     */
    public function init(): void
    {
        parent::init();

        // The Recurrence field type, so it can be added to field layouts.
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Recurrence::class;
        });

        // `craft.recurrence` in templates (occurrence queries, ICS URLs…).
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('recurrence', RecurrenceVariable::class);
        });

        // `occursBetween()` on every element query.
        Event::on(ElementQuery::class, ElementQuery::EVENT_DEFINE_BEHAVIORS, function(DefineBehaviorsEvent $event) {
            $event->behaviors['recurrence'] = ElementQueryBehavior::class;
        });

        // The plugin's fixed site routes (ICS files). Dated pages are routed
        // by the site itself; see _siteUrlRules().
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules = array_merge($event->rules, $this->_siteUrlRules());
        });

        // Every layout owner (entry types, volumes, users…) validates its
        // layout on save. Allow only one Recurrence field per layout, since
        // dated URLs and ICS files belong to the element as a whole and two
        // schedules would make them ambiguous.
        Event::on(FieldLayout::class, Model::EVENT_AFTER_VALIDATE, function(Event $event) {
            /** @var FieldLayout $layout */
            $layout = $event->sender;
            if (count(self::recurrenceFields($layout)) > 1) {
                $layout->addError('customFields', Craft::t('recurrence', 'A field layout can only have one Recurrence field.'));
            }
        });

        // Craft stores no settings on install; write the defaults so project
        // config holds the full set from the start. Skipped while `craft up`
        // applies a deployed install, whose settings arrive with it.
        Event::on(Plugins::class, Plugins::EVENT_AFTER_INSTALL_PLUGIN, function(PluginEvent $event) {
            $projectConfig = Craft::$app->getProjectConfig();
            if (
                $event->plugin === $this &&
                !$projectConfig->getIsApplyingExternalChanges() &&
                $projectConfig->get($this->settingsPath()) === null
            ) {
                $projectConfig->set($this->settingsPath(), (new Settings())->toArray(), 'Save default Recurrence settings');
            }
        });

        // Rewrite the occurrences table when settings that shape it change. Listening on
        // project config (rather than afterSaveSettings()) catches CP saves
        // and `craft up` applying a deployed change alike.
        $settingsPath = $this->settingsPath();
        Craft::$app->getProjectConfig()
            ->onAdd($settingsPath, [$this, 'handleChangedSettings'])
            ->onUpdate($settingsPath, [$this, 'handleChangedSettings']);
    }

    /**
     * Project config path of the plugin's settings, `plugins.{handle}.settings`.
     * Craft builds the same path when it saves plugin settings. Used by init()
     * for the installation defaults and the change listeners.
     */
    private function settingsPath(): string
    {
        return sprintf('%s.%s.settings', ProjectConfig::PATH_PLUGINS, $this->handle);
    }

    /**
     * Queues a full rebuild of the occurrences table ({@see RebuildOccurrences})
     * when an OCCURRENCES_TABLE_SETTINGS value (horizon, past months kept,
     * maximum dates) changes, since those decide which dates the table holds.
     * Other settings don't affect the table and are ignored.
     *
     * Registered in init() as a project config add/update handler.
     */
    public function handleChangedSettings(ConfigEvent $event): void
    {
        // A single save can fire both add and update; one rebuild covers them.
        if ($this->rebuildQueued) {
            return;
        }

        $old = (array)($event->oldValue ?? []);
        $new = (array)($event->newValue ?? []);

        // Loose comparison: project config may hold "12" where the model has 12.
        foreach (self::OCCURRENCES_TABLE_SETTINGS as $key) {
            if (($old[$key] ?? null) != ($new[$key] ?? null)) {
                Craft::$app->getQueue()->push(new RebuildOccurrences());
                $this->rebuildQueued = true;
                return;
            }
        }
    }

    /**
     * The service that keeps the occurrences table in step with saves; used by
     * the field, the console commands and the rebuild job.
     */
    public function getOccurrences(): Occurrences
    {
        return $this->get('occurrences');
    }

    /**
     * The Recurrence field in an element's layout, if any. Layouts are limited
     * to one; should a second slip in (e.g. hand-edited project config), the
     * first wins.
     *
     * Used wherever code has an element but not a field handle: the
     * controllers, `craft.recurrence`, ICS output and the occurrences service.
     */
    public function recurrenceField(ElementInterface $element): ?Recurrence
    {
        $layout = $element->getFieldLayout();
        return $layout ? (self::recurrenceFields($layout)[0] ?? null) : null;
    }

    /**
     * Every Recurrence field in a layout, in layout order. Used by the
     * one-field-per-layout validation in init(), recurrenceField(), and to find
     * the layouts (and so the fields) offered as ICS sources.
     *
     * @return Recurrence[]
     */
    public static function recurrenceFields(FieldLayout $layout): array
    {
        return array_values(array_filter($layout->getCustomFields(), fn($field) => $field instanceof Recurrence));
    }

    /** The settings model Craft fills from project config and config/recurrence.php. */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Renders Settings → Plugins → Recurrence (`_settings.twig`). Settings set
     * in config/recurrence.php are shown locked; their stored values are passed
     * along so the template can re-post them.
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('recurrence/_settings.twig', [
            // Values in effect, including any from config/recurrence.php.
            'settings' => $this->getSettings(),
            // Keys locked by config/recurrence.php.
            'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile($this->handle)),
            // Craft replaces the stored settings with only the posted keys, and
            // locked (disabled) inputs aren't posted, so the template re-posts
            // these stored values as hidden inputs to keep them in project config.
            'stored' => Craft::$app->getPlugins()->getStoredPluginInfo($this->handle)['settings'] ?? [],
            // Options for the calendar description/location source selects.
            'descriptionFields' => IcsDetails::fieldOptions(IcsDetails::DESCRIPTION),
            'locationFields' => IcsDetails::fieldOptions(IcsDetails::LOCATION),
        ]);
    }

    /**
     * The plugin's own fixed site routes, registered in init(): a section's
     * subscribable feed and one entry's calendar file, both under the plugin's
     * `recurrence/` prefix so they can't collide with site URLs.
     *
     * Dated pages (`{entry-uri}/{key}`) aren't routed here: the site points its
     * own routes at `recurrence/occurrence/view` (see the README), so the plugin
     * never claims URLs a site didn't ask for.
     */
    private function _siteUrlRules(): array
    {
        return [
            'recurrence/feed/<section:[\w-]+>.ics' => 'recurrence/ics/feed',
            'recurrence/ics/<uid:' . StringHelper::UUID_PATTERN . '>.ics' => 'recurrence/ics/element',
        ];
    }
}
