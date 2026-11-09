<?php

use _generated\UnitTesterActions;
use Codeception\Actor;

/**
 * The unit suite's actor. Codeception generates its actions into
 * `_generated/` from the modules in unit.suite.yml.
 */
class UnitTester extends Actor
{
    use UnitTesterActions;
}
