<?php

declare(strict_types=1);

/**
 * Registers the library with the default integration under the display_errors value given as the
 * first argument, then raises one ordinary warning. PHP's own display of that warning shows up on
 * the stream PHP picks for that value - or nowhere.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use voku\ErrorHandlerLib\ErrorHandlerLib;

error_reporting(E_ALL);
ini_set('log_errors', '0');
ini_set('html_errors', '0');
// The library's own error_log() call would otherwise go to stderr as well.
ini_set('error_log', '/dev/null');
ini_set('display_errors', (string) ($argv[1] ?? '0'));

(new ErrorHandlerLib())->register();

trigger_error('native-check', E_USER_WARNING);
