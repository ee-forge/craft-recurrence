<?php

// Use the nearest Composer autoloader above the tests: the plugin's own
// `vendor/` when it's been `composer install`ed standalone, otherwise the host
// Craft project's (the plugin sitting in a path repository or the host's
// `vendor/`), so it doesn't need a second copy of Craft to be tested there.
$autoload = null;
for ($dir = dirname(__DIR__); ; $dir = dirname($dir)) {
    if (is_file("$dir/vendor/autoload.php")) {
        $autoload = "$dir/vendor/autoload.php";
        break;
    }
    if (dirname($dir) === $dir) {
        break;
    }
}

if ($autoload === null) {
    throw new RuntimeException('No vendor/autoload.php found; run `composer install` in the plugin or its host Craft project.');
}

require $autoload;
