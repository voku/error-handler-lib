<?php

declare(strict_types=1);

/**
 * Two nested registrations, then a real E_COMPILE_ERROR at shutdown.
 *
 * PHP runs shutdown functions in registration order, so a per-instance shutdown callback would let
 * the outer (first) registration report a fatal error that belongs to the inner, active one.
 * With "unregister-inner" the inner registration is gone again and the outer one must report it.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use voku\ErrorHandlerLib\ErrorHandlerDefaultIntegration;
use voku\ErrorHandlerLib\ErrorHandlerIntegrationInterface;
use voku\ErrorHandlerLib\ErrorHandlerLib;
use voku\ErrorHandlerLib\SuppressedDiagnosticPolicy;

final class LabelledIntegration implements ErrorHandlerIntegrationInterface
{
    private ErrorHandlerDefaultIntegration $default;

    public function __construct(private readonly string $label)
    {
        $this->default = new ErrorHandlerDefaultIntegration();
    }

    public function shouldEchoOutput(): bool
    {
        return false;
    }

    public function shouldDisplayErrors(): bool
    {
        return false;
    }

    public function isTestingEnvironment(): bool
    {
        return false;
    }

    public function suppressedDiagnosticPolicy(): SuppressedDiagnosticPolicy
    {
        return SuppressedDiagnosticPolicy::Observe;
    }

    public function isDebugBarRequest(): bool
    {
        return false;
    }

    public function addDebugBarMessage(int $errno, string $description): void
    {
    }

    public function getGlobalInfo(): string
    {
        return '';
    }

    public function includeTraceArguments(): bool
    {
        return false;
    }

    public function sanitizeErrorDetails(string $details): string
    {
        return $details;
    }

    public function renderCriticalError(string $details, bool $echoOutput): never
    {
        fwrite(STDERR, 'HANDLED-BY:' . $this->label . "\n" . $details);

        exit(1);
    }

    public function renderNonCriticalError(string $label, string $details, bool $echoOutput): void
    {
    }

    public function renderJavaScriptErrorHandler(): string
    {
        return $this->default->renderJavaScriptErrorHandler();
    }

    public function describeObject(object $value): ?string
    {
        return null;
    }
}

ini_set('log_errors', '0');

(new ErrorHandlerLib(new LabelledIntegration('outer')))->register();
$inner = (new ErrorHandlerLib(new LabelledIntegration('inner')))->register();

if (($argv[1] ?? '') === 'unregister-inner') {
    $inner->unregister();
}

final class FixtureClassDeclaredTwice
{
}

include __DIR__ . '/redeclare-fixture-class.php';
