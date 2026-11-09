<?php

namespace eeforge\recurrence\tests;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\events\PluginEvent;
use craft\services\Plugins;
use craft\services\ProjectConfig;
use craft\test\TestCase;
use craft\web\View;
use eeforge\recurrence\models\Settings;
use eeforge\recurrence\Plugin;
use UnitTester;

/**
 * Plugin settings: validation, saving to project config, the rebuild that
 * follows a change to the occurrences window, install defaults, and the
 * settings page. The CP form itself is covered by the functional suite.
 */
class SettingsTest extends TestCase
{
    /** The RebuildOccurrences job's description in the queue table. */
    private const REBUILD_JOB = 'Rebuilding event dates';

    private const SETTINGS_PATH = ProjectConfig::PATH_PLUGINS . '.recurrence.settings';

    /** A config/recurrence.php written by a test, removed afterwards. */
    private ?string $_configFile = null;

    protected UnitTester $tester;

    protected function _after(): void
    {
        if ($this->_configFile !== null && is_file($this->_configFile)) {
            unlink($this->_configFile);
        }
    }

    public function testValidSettingsPass(): void
    {
        $settings = new Settings([
            'icsDescriptionSource' => 'template',
            'icsLocationSource' => '0117e3b4-4a8b-4142-b399-f4b5999c9e61',
        ]);

        $this->assertTrue($settings->validate(), implode(' ', $settings->getFirstErrors()));
    }

    public function testMonthsMustBePositive(): void
    {
        $settings = new Settings(['horizonMonths' => 0, 'pastMonths' => -1]);

        $this->assertFalse($settings->validate());
        // Errors name the fields as the settings page labels them.
        $this->assertSame('Horizon must be no less than 1.', $settings->getFirstError('horizonMonths'));
        $this->assertSame('Pruning must be no less than 1.', $settings->getFirstError('pastMonths'));
    }

    public function testMaxOccurrencesIsCapped(): void
    {
        $settings = new Settings(['maxOccurrences' => 20000]);

        $this->assertFalse($settings->validate());
        $this->assertTrue($settings->hasErrors('maxOccurrences'));
    }

    public function testSourcesMustBeBlankTemplateOrAFieldUid(): void
    {
        $settings = new Settings(['icsDescriptionSource' => 'body', 'icsLocationSource' => 'templates']);

        $this->assertFalse($settings->validate());
        $this->assertTrue($settings->hasErrors('icsDescriptionSource'));
        $this->assertTrue($settings->hasErrors('icsLocationSource'));
    }

    public function testSavingWritesProjectConfig(): void
    {
        $this->assertTrue($this->_save(['horizonMonths' => 24, 'icsFeedName' => '{section} calendar']));

        $this->assertSame(24, Plugin::getInstance()->getSettings()->horizonMonths);
        $this->assertSame(24, Craft::$app->getProjectConfig()->get(self::SETTINGS_PATH . '.horizonMonths'));
        $this->assertSame('{section} calendar', Craft::$app->getProjectConfig()->get(self::SETTINGS_PATH . '.icsFeedName'));
    }

    public function testInvalidSettingsAreNotSaved(): void
    {
        $this->assertFalse($this->_save(['horizonMonths' => 0]));

        $this->assertSame(18, Craft::$app->getProjectConfig()->get(self::SETTINGS_PATH . '.horizonMonths'));
    }

    public function testChangingTheOccurrencesWindowQueuesOneRebuild(): void
    {
        $this->assertTrue($this->_save(['horizonMonths' => 24, 'pastMonths' => 6, 'maxOccurrences' => 100]));

        $this->assertSame(1, $this->_rebuildJobs());
    }

    public function testChangingCalendarSettingsDoesNotRebuild(): void
    {
        $this->assertTrue($this->_save(['icsFeedName' => '{section} calendar', 'icsDescriptionSource' => 'template']));

        $this->tester->assertNotPushedToQueue(self::REBUILD_JOB);
    }

    public function testSavingUnchangedValuesDoesNotRebuild(): void
    {
        $this->assertTrue($this->_save((new Settings())->toArray()));

        $this->tester->assertNotPushedToQueue(self::REBUILD_JOB);
    }

    public function testNumericStringsCountAsUnchanged(): void
    {
        // Project config may hold "12" where the model has 12.
        Craft::$app->getProjectConfig()->set(self::SETTINGS_PATH . '.pastMonths', '12');

        $this->tester->assertNotPushedToQueue(self::REBUILD_JOB);
    }

    public function testSavingSomeKeysDoesNotRebuild(): void
    {
        // Keys left out fall back to the defaults, which are the values in effect.
        $this->assertTrue(Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), ['icsFeedName' => '{section} calendar']));

        $this->tester->assertNotPushedToQueue(self::REBUILD_JOB);
    }

    public function testInstallWritesTheDefaultSettings(): void
    {
        Craft::$app->getProjectConfig()->remove(self::SETTINGS_PATH);
        $this->_triggerInstall();

        $this->assertEquals((new Settings())->toArray(), Craft::$app->getProjectConfig()->get(self::SETTINGS_PATH));
        $this->tester->assertNotPushedToQueue(self::REBUILD_JOB);
    }

    public function testSettingsArrivingWithANewWindowRebuild(): void
    {
        // E.g. `craft up` installing the plugin with a non-default horizon.
        Craft::$app->getProjectConfig()->remove(self::SETTINGS_PATH);
        Craft::$app->getProjectConfig()->set(self::SETTINGS_PATH, ['horizonMonths' => 24] + (new Settings())->toArray());

        $this->tester->assertPushedToQueue(self::REBUILD_JOB);
    }

    public function testInstallKeepsSettingsThatAlreadyExist(): void
    {
        Craft::$app->getProjectConfig()->set(self::SETTINGS_PATH . '.horizonMonths', 24);
        $this->_triggerInstall();

        $this->assertSame(24, Craft::$app->getProjectConfig()->get(self::SETTINGS_PATH . '.horizonMonths'));
    }

    public function testSettingsPageShowsTheCurrentValues(): void
    {
        $this->assertTrue($this->_save(['horizonMonths' => 24]));
        $html = $this->_settingsHtml();

        $this->assertMatchesRegularExpression('/<input[^>]*name="horizonMonths"[^>]*value="24"/', $html);
        $this->assertStringContainsString('Custom template…', $html);
        $this->assertStringContainsString('Calendar description template', $html);
        $this->assertStringContainsString('Feed name', $html);
        $this->assertStringNotContainsString('config/recurrence.php', $html);
    }

    public function testSettingsPageLocksConfigFileSettingsAndRepostsStoredValues(): void
    {
        $this->_writeConfigFile(['horizonMonths' => 36]);
        $html = $this->_settingsHtml();

        $this->assertMatchesRegularExpression('/<input[^>]*name="horizonMonths"[^>]*disabled/', $html);
        $this->assertStringContainsString('config/recurrence.php', $html);
        // The stored 18, not the file's 36, so saving the form leaves project config alone.
        $this->assertMatchesRegularExpression('/<input type="hidden" name="horizonMonths" value="18"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="pastMonths"[^>]*disabled/', $html);
    }

    /**
     * Saves settings the way the CP form does: every setting is posted, so
     * `$changes` is merged into the current values. (savePluginSettings()
     * stores only the keys it's given.)
     */
    private function _save(array $changes): bool
    {
        $plugin = Plugin::getInstance();
        return Craft::$app->getPlugins()->savePluginSettings($plugin, $changes + $plugin->getSettings()->toArray());
    }

    private function _rebuildJobs(): int
    {
        return (int)(new Query())
            ->from([Table::QUEUE])
            ->where(['description' => self::REBUILD_JOB])
            ->count();
    }

    /** Fires the event Craft triggers after installing the plugin. */
    private function _triggerInstall(): void
    {
        Craft::$app->getPlugins()->trigger(Plugins::EVENT_AFTER_INSTALL_PLUGIN, new PluginEvent([
            'plugin' => Plugin::getInstance(),
        ]));
    }

    private function _settingsHtml(): string
    {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        try {
            return $this->invokeMethod(Plugin::getInstance(), 'settingsHtml');
        } finally {
            $view->setTemplateMode($mode);
        }
    }

    private function _writeConfigFile(array $config): void
    {
        $this->_configFile = Craft::$app->getConfig()->getConfigFilePath('recurrence');
        file_put_contents($this->_configFile, '<?php return ' . var_export($config, true) . ';');
    }
}
