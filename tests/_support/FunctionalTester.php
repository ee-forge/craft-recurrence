<?php

use _generated\FunctionalTesterActions;
use Codeception\Actor;

/**
 * The functional suite's actor. Codeception generates its actions into
 * `_generated/` from the modules in functional.suite.yml.
 */
class FunctionalTester extends Actor
{
    use FunctionalTesterActions;
}
