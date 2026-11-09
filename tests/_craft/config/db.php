<?php

use craft\helpers\App;

return [
    'dsn' => App::env('CRAFT_DB_DSN'),
    'user' => App::env('CRAFT_DB_USER'),
    'password' => App::env('CRAFT_DB_PASSWORD'),
    'tablePrefix' => App::env('CRAFT_DB_TABLE_PREFIX'),
];
