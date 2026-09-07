<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the fatal/uncaught regression fixtures.
 *
 * It installs the most hostile host policy the library allows - "ignore everything PHP suppressed"
 * plus a skip decider that wants to drop every diagnostic - and then empties error_reporting().
 * Whatever the individual fixture does next must still reach renderCriticalError().
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use voku\ErrorHandlerLib\ErrorHandlerDefaultIntegration;
use voku\ErrorHandlerLib\ErrorHandlerIntegrationInterface;
use voku\ErrorHandlerLib\ErrorHandlerLib;
use voku\ErrorHandlerLib\ErrorHandlerSkipDeciderInterface;
use voku\ErrorHandlerLib\SuppressedDiagnosticPolicy;

/**
 * A tool-context host policy: keep everything else default, but ignore PHP-suppressed diagnostics.
 */
final class IgnoreSuppressedIntegration implements ErrorHandlerIntegrationInterface
{
    private ErrorHandlerDefaultIntegration $default;

    public function __construct()
    {
        $this->default = new ErrorHandlerDefaultIntegration();
    }

    public function suppressedDiagnosticPolicy(): SuppressedDiagnosticPolicy
    {
        return SuppressedDiagnosticPolicy::Ignore;
    }

    public function shouldEchoOutput(): bool
    {
        return true;
    }

    public function shouldDisplayErrors(): bool
    {
        return false;
    }

    public function isTestingEnvironment(): bool
    {
        return $this->default->isTestingEnvironment();
    }

    public function isDebugBarRequest(): bool
    {
        return $this->default->isDebugBarRequest();
    }

    public function addDebugBarMessage(int $errno, string $description): void
    {
        $this->default->addDebugBarMessage($errno, $description);
    }

    public function getGlobalInfo(): string
    {
        return $this->default->getGlobalInfo();
    }

    public function sanitizeErrorDetails(string $details): string
    {
        return $this->default->sanitizeErrorDetails($details);
    }

    public function renderCriticalError(string $details, bool $echoOutput): never
    {
        $this->default->renderCriticalError($details, $echoOutput);
    }

    public function renderNonCriticalError(string $label, string $details, bool $echoOutput): void
    {
        $this->default->renderNonCriticalError($label, $details, $echoOutput);
    }

    public function renderJavaScriptErrorHandler(): string
    {
        return $this->default->renderJavaScriptErrorHandler();
    }

    public function describeObject(object $value): ?string
    {
        return $this->default->describeObject($value);
    }
}

final class SkipEverythingDecider implements ErrorHandlerSkipDeciderInterface
{
    public function shouldSkip(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        return true;
    }
}

(new ErrorHandlerLib(new IgnoreSuppressedIntegration(), new SkipEverythingDecider()))->register();

error_reporting(0);
