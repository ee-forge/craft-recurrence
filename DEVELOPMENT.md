# Development

## Setup

From the plugin's directory:

```
composer install
```

This installs Craft, PHPUnit and the code-quality tools into the plugin's own `vendor/`.

## Tests

The tests use Craft's own test framework: Codeception with the `\craft\test\Craft` module, set up like Craft's example suite (`vendor/craftcms/cms/src/test/internal/example-test-suite`). Before each run the module installs Craft into a test database from the suite's project config, which includes the plugin, and each test runs in a transaction that's rolled back afterwards.

**The test database is wiped on every run.** Give it its own database; never point it at a site's.

1. Create the database. With DDEV, from any DDEV project that mounts the plugin:

   ```
   ddev mysql -uroot -proot -e "CREATE DATABASE recurrence_test; GRANT ALL ON recurrence_test.* TO 'db'@'%';"
   ```

2. Copy `tests/.env.example` to `tests/.env` (git-ignored) and adjust the connection if you're not using DDEV.
3. Run the tests where the database is reachable. For DDEV, that's inside the web container:

   ```
   ddev exec --dir /path/to/recurrence composer test
   ```

   `composer test` runs `codecept run`, both suites; pass Codeception options after `--`, e.g. `composer test -- unit OccurrencesTest` or `composer test -- functional`.

The **unit** suite (`tests/unit/`) calls the plugin's PHP directly. The **functional** suite (`tests/functional/`) makes CP requests through Craft, logged in as the admin user the test install creates.

| Test | Covers |
| --- | --- |
| `ExpanderTest`, `IcsTest` | The recurrence engine and ICS output, including the DST cases. |
| `PluginTest` | What the plugin wires into Craft: the service, table, field type, query behavior and settings. |
| `RecurrenceFieldTest` | Normalizing, serializing and validating field values. |
| `OccurrencesTest` | Saving entries in a real section, the occurrences table, `OccurrenceQuery`, `occursBetween()` and occurrence URLs. |
| `SettingsTest` | Settings validation, saving to project config, the rebuild queued when the occurrences window changes (and not otherwise), install defaults, and the settings page, including settings locked by `config/recurrence.php`. |
| `functional/SettingsCest` | The CP settings form: it shows the settings, saves them, rejects invalid values, and keeps the stored value of a locked setting. |

The suite's files:

- `codeception.yml`: the Craft module's config: the project config folder and the plugin to install.
- `tests/_bootstrap.php`: Craft's path constants.
- `tests/_craft/project/`: the test site's schema as project config: an `events` channel with an `event` entry type and an `eventSchedule` Recurrence field, plus the site, system and plugin settings an install needs. The module copies it to `tests/_craft/config/project/` (git-ignored) when the suite starts.
- `tests/_craft/config/`: the test app's config. `db.php` reads the connection from `tests/.env`, which also sets `CRAFT_SECURITY_KEY` (Craft maps `CRAFT_*` variables onto general config); the functional suite needs it to log in. Tests that need a `config/recurrence.php` write it here and delete it afterwards.
- `tests/_craft/storage/`: runtime files, git-ignored.
- `tests/unit/`: unit tests; each extends `craft\test\TestCase`. Tests create the entries they need, since saving them is usually what's being tested.
- `tests/functional/`: functional tests, as Codeception Cests.

### Things to know when writing tests

- **`savePluginSettings()` stores only the keys it's given.** The CP form posts every setting; to test what the form does, pass the full set (see `SettingsTest::_save()`).

### Changing the test schema

The files in `tests/_craft/project/` are ordinary project config, so they're easiest to generate rather than write by hand: in a temporary test, build the schema with Craft's services (`saveField()`, `saveEntryType()`, `saveSection()`), then call `Craft::$app->getProjectConfig()->writeYamlFiles(true)` and copy `Craft::$app->getPath()->getProjectConfigPath()` over `tests/_craft/project/`. Copy it inside the test: the module deletes its working copy when the suite ends.

`project.yaml` records the Craft and plugin schema versions it was written with (`system.schemaVersion`, `plugins.recurrence.schemaVersion`). If a Craft update or a plugin migration changes them and the suite won't install, regenerate the files the same way.

## Code quality

| Command | Does |
| --- | --- |
| `composer rector` | Shows Rector's suggested changes as diffs. |
| `composer fix-rector` | Applies them. |
| `composer check-cs` | Checks coding style with ECS (`craftcms/ecs`). |
| `composer fix-cs` | Fixes coding style. |
| `composer phpstan` | Static analysis with PHPStan (`craftcms/phpstan`), at level 5. |

Run them in that order before committing: Rector's output isn't always in ECS's style, so ECS tidies up after it, and PHPStan checks the result.

- **Rector** (`rector.php`) applies the PHP 8.2 sets plus dead-code, code-quality, type-declaration and early-return rules. It skips two rules that conflict with Craft's conventions below: constructor property promotion and `declare(strict_types=1)`.
- **PHPStan** (`phpstan.neon`) has one ignored error, `method.childReturnType` on `OccurrenceQuery::one()`. Craft's `Query::one()` already returns `TValue|null` where Yii's `QueryInterface` declares `array|bool`, so any subclass overriding it inherits the mismatch. The override is needed to return an `Occurrence` rather than a raw row.

## Conventions

The code follows [Craft's coding guidelines](https://craftcms.com/docs/5.x/extend/coding-guidelines.html). The ones that most often come up:

- Private properties and methods start with an underscore (`$_events`, `_buildQuery()`). That's why the `Ics` constructor doesn't promote its parameters.
- No `declare(strict_types=1)`, as in Craft core.
- Overrides with nothing to add have only `@inheritdoc`. `@param` and `@return` descriptions start lowercase and have no trailing period.
- Translate with `Craft::t('recurrence', …)`.
