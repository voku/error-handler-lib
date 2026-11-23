<?php

declare(strict_types=1);

namespace voku\ErrorHandlerLib;

final class ErrorHandlerDefaultIntegration implements ErrorHandlerIntegrationInterface
{
    private const int HTTP_INTERNAL_SERVER_ERROR_STATUS = 500;

    public function shouldEchoOutput(): bool
    {
        return $this->shouldDisplayErrors();
    }

    /**
     * Whether PHP itself displays errors, by PHP's own reading of display_errors
     * (php-src `php_get_display_errors_mode()`): "stderr", "stdout" and "2" display, " on" with
     * whitespace does not. A boolean filter gets all four wrong. A numeric mode counts by its low
     * byte only (php-src keeps it in a uint8_t), so "256" does not display.
     */
    public function shouldDisplayErrors(): bool
    {
        $value = (string) ini_get('display_errors');
        $lower = strtolower($value);

        if (in_array($lower, ['on', 'yes', 'true', 'stdout', 'stderr'], true)) {
            return true;
        }

        return (((int) $value) & 0xFF) !== 0;
    }

    public function isTestingEnvironment(): bool
    {
        return false;
    }

    /**
     * Suppressed diagnostics stay observable by default.
     *
     * Hiding them would remove the main reason to install this handler at all; a host that
     * wants PHP's normal suppression semantics opts out explicitly.
     */
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
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'ERROR ON CLI:' . $details);

            exit(1);
        }

        if (!headers_sent()) {
            http_response_code(self::HTTP_INTERNAL_SERVER_ERROR_STATUS);
        }

        if ($echoOutput) {
            echo $this->buildHtmlMessage('Error', $details);
        } else {
            echo 'An unexpected error occurred.';
        }

        exit(1);
    }

    public function renderNonCriticalError(string $label, string $details, bool $echoOutput): void
    {
        if (!$echoOutput) {
            return;
        }

        if (PHP_SAPI === 'cli') {
            echo strtoupper($label) . ' ON CLI:' . $details;

            return;
        }

        echo $this->buildHtmlMessage($label, $details);
    }

    public function renderJavaScriptErrorHandler(): string
    {
        return '';
    }

    public function describeObject(object $value): ?string
    {
        return null;
    }

    private function buildHtmlMessage(string $label, string $details): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><pre style="margin-top: 25px;"><code>'
            . htmlspecialchars($label . ':' . $details, ENT_QUOTES)
            . '</code></pre></html>';
    }
}
