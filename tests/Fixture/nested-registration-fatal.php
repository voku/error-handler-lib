<?php

declare(strict_types=1);

/**
 * Two nested registrations, then a real E_COMPILE_ERROR at shutdown.
 *
 * PHP runs shutdown functions in registration order, so a per-instance shutdown callback would let
 * the outer (first) registration report a fatal error that belongs to the inner, active one.
 * With "unregister-inner" the inner registration is gone again and the outer one must report it.
 * "host-shutdown-overwrites-last-error" and "fatal-inside-function" are explained where they apply.
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

    public function recordSuppressedDiagnosticsInErrorGetLast(): bool
    {
        return true;
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

$mode = (string) ($argv[1] ?? '');

if ($mode === 'host-shutdown-overwrites-last-error') {
    // Registered before the library's dispatcher, so it runs first. The suppressed warning is
    // returned to PHP (`false`), which records it and would replace the fatal in error_get_last().
    register_shutdown_function(static function (): void {
        @file_get_contents(__DIR__ . '/does-not-exist.txt');
    });
}

// PHP 8.5+ only; older runtimes do not know the directive and ini_set() just returns false.
ini_set('fatal_error_backtraces', '1');

(new ErrorHandlerLib(new LabelledIntegration('outer')))->register();
$inner = (new ErrorHandlerLib(new LabelledIntegration('inner')))->register();

if ($mode === 'unregister-inner') {
    $inner->unregister();
}

if ($mode === 'recovered-opcache-compile-error') {
    // opcache_compile_file() catches the compile bailout and returns false; PHP up to 8.4 also
    // raises a "could not compile" warning, which is what used to make the handler keep a copy of
    // the compile error. The script recovers and clears the last error, so nothing may be reported
    // as fatal at shutdown.
    $broken = sys_get_temp_dir() . '/error-handler-lib-compile-error-' . getmypid() . '.php';
    file_put_contents($broken, "<?php\nfunction declaredTwice() {}\nfunction declaredTwice() {}\n");

    $compiled = @opcache_compile_file($broken);
    unlink($broken);

    $last = error_get_last();
    if (
        $compiled === false
        && is_array($last)
        && (str_contains($last['message'], 'could not compile') || $last['type'] === E_COMPILE_ERROR)
    ) {
        echo "OPCACHE-RECOVERED\n";
    }

    error_clear_last();

    echo "SCRIPT-FINISHED\n";

    return;
}

final class FixtureClassDeclaredTwice
{
}

function includeRedeclaringFile(): void
{
    include __DIR__ . '/redeclare-fixture-class.php';
}

includeRedeclaringFile();
