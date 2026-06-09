<?php

declare(strict_types=1);

namespace voku\ErrorHandlerLib;

interface ErrorHandlerSkipDeciderInterface
{
    public function shouldSkip(int $errno, string $errstr, string $errfile, int $errline): bool;
}
