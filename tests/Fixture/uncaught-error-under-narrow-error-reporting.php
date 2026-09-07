<?php

declare(strict_types=1);

/**
 * A failing `require` raises a non-critical E_WARNING first (which the hostile policy must swallow)
 * and then throws an uncaught `Error` (which it must not). The path is built at runtime so that
 * static analysis of the test suite does not have to model a deliberately broken include.
 */

require __DIR__ . '/hostile-policy-bootstrap.php';

$missingInclude = (string) ($argv[1] ?? __DIR__ . '/this-include-does-not-exist-on-purpose.php');

require $missingInclude;
