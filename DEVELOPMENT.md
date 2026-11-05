# Development

Standalone, from the plugin's directory:

```
composer install
vendor/bin/phpunit
```

Inside a Craft project that requires the plugin from a Composer path repository, the tests can instead use the project's autoloader and PHPUnit, with no second `vendor/` in the plugin. The bootstrap uses the nearest `vendor/autoload.php` above the plugin. From the project root:

```
vendor/bin/phpunit -c path/to/recurrence/phpunit.xml.dist
```

The recurrence engine (`models/Schedule`, `services/Expander`, `helpers/Ics`) has no Craft dependency and is fully unit-tested, including the DST cases.
