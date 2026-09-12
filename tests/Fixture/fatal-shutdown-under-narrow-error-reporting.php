<?php

declare(strict_types=1);

/**
 * Redeclaring a class raises a real E_COMPILE_ERROR that is not routed through the exception
 * handler, so this exercises the register_shutdown_function()/error_get_last() path specifically.
 */

require __DIR__ . '/hostile-policy-bootstrap.php';

final class FixtureClassDeclaredTwice
{
}

$redeclaringInclude = (string) ($argv[1] ?? __DIR__ . '/redeclare-fixture-class.php');

include $redeclaringInclude;
