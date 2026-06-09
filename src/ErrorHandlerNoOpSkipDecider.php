<?php

declare(strict_types=1);

namespace voku\ErrorHandlerLib;

final class ErrorHandlerNoOpSkipDecider implements ErrorHandlerSkipDeciderInterface
{
    public function shouldSkip(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        return false;
    }
}
