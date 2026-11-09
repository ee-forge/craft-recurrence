<?php

use craft\test\TestSetup;

ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');

const CRAFT_TESTS_PATH = __DIR__;
const CRAFT_STORAGE_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'storage';
const CRAFT_TEMPLATES_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'templates';
const CRAFT_CONFIG_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'config';
const CRAFT_MIGRATIONS_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'migrations';
const CRAFT_TRANSLATIONS_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'translations';
define('CRAFT_ROOT_PATH', dirname(__DIR__));
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor');

// TestSetup resolves these with realpath(), so they must exist. Storage is
// git-ignored; the others are optional and usually empty.
foreach ([CRAFT_STORAGE_PATH . '/logs', CRAFT_STORAGE_PATH . '/runtime', CRAFT_MIGRATIONS_PATH, CRAFT_TRANSLATIONS_PATH] as $path) {
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

TestSetup::configureCraft();
