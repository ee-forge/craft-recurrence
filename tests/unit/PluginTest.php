<?php

namespace eeforge\recurrence\tests;

use Craft;
use craft\elements\Entry;
use craft\test\TestCase;
use eeforge\recurrence\behaviors\ElementQueryBehavior;
use eeforge\recurrence\fields\Recurrence;
use eeforge\recurrence\models\Settings;
use eeforge\recurrence\Plugin;
use eeforge\recurrence\services\Occurrences;

/**
 * What Plugin::init() and the install migration wire into Craft.
 */
class PluginTest extends TestCase
{
    public function testPluginIsInstalled(): void
    {
        $this->assertTrue(Craft::$app->getPlugins()->isPluginInstalled('recurrence'));
        $this->assertInstanceOf(Plugin::class, Plugin::getInstance());
    }

    public function testOccurrencesServiceIsRegistered(): void
    {
        $this->assertInstanceOf(Occurrences::class, Plugin::getInstance()->getOccurrences());
    }

    public function testInstallCreatesTheOccurrencesTable(): void
    {
        $this->assertNotNull(Craft::$app->getDb()->getTableSchema(Plugin::TABLE_OCCURRENCES));
    }

    public function testFieldTypeIsRegistered(): void
    {
        $this->assertContains(Recurrence::class, Craft::$app->getFields()->getAllFieldTypes());
    }

    public function testElementQueriesGetOccursBetween(): void
    {
        $this->assertInstanceOf(ElementQueryBehavior::class, Entry::find()->getBehavior('recurrence'));
    }

    public function testSettingsUseTheModelDefaults(): void
    {
        $settings = Plugin::getInstance()->getSettings();

        $this->assertInstanceOf(Settings::class, $settings);
        $this->assertSame((new Settings())->toArray(), $settings->toArray());
    }
}
