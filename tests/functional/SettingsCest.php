<?php

namespace eeforge\recurrence\tests;

use Craft;
use craft\elements\User;
use craft\services\ProjectConfig;
use FunctionalTester;

/**
 * Settings → Plugins → Recurrence in the CP, submitted the way a browser
 * would: an admin opens the page and posts the form, disabled inputs left out.
 */
class SettingsCest
{
    private const PAGE = '?p=admin/settings/plugins/recurrence';
    private const SETTINGS_PATH = ProjectConfig::PATH_PLUGINS . '.recurrence.settings';

    /** A config/recurrence.php written by a test, removed afterwards. */
    private ?string $_configFile = null;

    public function _before(FunctionalTester $I): void
    {
        $I->amLoggedInAs(User::find()->admin()->one());
    }

    public function _after(FunctionalTester $I): void
    {
        if ($this->_configFile !== null && is_file($this->_configFile)) {
            unlink($this->_configFile);
        }
    }

    public function pageShowsTheSettings(FunctionalTester $I): void
    {
        $I->amOnPage(self::PAGE);

        $I->seeResponseCodeIs(200);
        $I->seeInField('settings[horizonMonths]', '18');
        $I->seeInField('settings[pastMonths]', '12');
        $I->seeInField('settings[maxOccurrences]', '500');
        $I->seeInField('settings[icsFeedName]', '{section}');
    }

    public function savingTheFormStoresTheSettings(FunctionalTester $I): void
    {
        $I->amOnPage(self::PAGE);
        $I->submitForm('#main-form', ['settings' => [
            'horizonMonths' => '24',
            'icsDescriptionSource' => 'template',
            'icsDescriptionTemplate' => '{title}',
            'icsFeedName' => '{section} calendar',
        ]]);

        $I->seeResponseCodeIsSuccessful();
        $I->amOnPage(self::PAGE);
        $I->seeInField('settings[horizonMonths]', '24');
        $I->seeInField('settings[icsFeedName]', '{section} calendar');
        $I->assertSame(24, $this->_stored('horizonMonths'));
        $I->assertSame('{title}', $this->_stored('icsDescriptionTemplate'));
        $I->assertSame(12, $this->_stored('pastMonths'), 'settings left as they were keep their values');
    }

    public function invalidSettingsAreRejected(FunctionalTester $I): void
    {
        $I->amOnPage(self::PAGE);
        $I->submitForm('#main-form', ['settings' => ['horizonMonths' => '0']]);

        $I->see('Horizon must be no less than 1.');
        $I->assertSame(18, $this->_stored('horizonMonths'));
    }

    public function lockedSettingsKeepTheirStoredValues(FunctionalTester $I): void
    {
        $this->_writeConfigFile(['horizonMonths' => 36]);

        $I->amOnPage(self::PAGE);
        $I->seeElement('input[name="settings[horizonMonths]"][disabled]');
        $I->see('config/recurrence.php');
        $I->submitForm('#main-form', ['settings' => ['pastMonths' => '6']]);

        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(6, $this->_stored('pastMonths'));
        // The stored value survives even though its input was disabled; the
        // file's 36 is never written to project config.
        $I->assertSame(18, $this->_stored('horizonMonths'));
    }

    private function _stored(string $key): mixed
    {
        return Craft::$app->getProjectConfig()->get(self::SETTINGS_PATH . ".$key");
    }

    private function _writeConfigFile(array $config): void
    {
        $this->_configFile = Craft::$app->getConfig()->getConfigFilePath('recurrence');
        file_put_contents($this->_configFile, '<?php return ' . var_export($config, true) . ';');
    }
}
