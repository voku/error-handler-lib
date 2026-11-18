<?php

declare(strict_types=1);

namespace voku\ErrorHandlerLib;

interface ErrorHandlerIntegrationInterface
{
    public function shouldEchoOutput(): bool;

    public function shouldDisplayErrors(): bool;

    public function isTestingEnvironment(): bool;

    /**
     * Decides whether diagnostics that PHP itself excluded from `error_reporting()`
     * (via `@` or an explicit mask) are still processed by the error handler.
     *
     * This is never consulted for critical/fatal diagnostics or uncaught exceptions.
     */
    public function suppressedDiagnosticPolicy(): SuppressedDiagnosticPolicy;

    public function isDebugBarRequest(): bool;

    public function addDebugBarMessage(int $errno, string $description): void;

    public function getGlobalInfo(): string;

    /**
     * Whether stack traces may render call argument values.
     *
     * Argument values are the part of a trace most likely to carry credentials or personal data,
     * so they are omitted unless the host opts in. Opting in also makes {@see ErrorHandlerLib::register()}
     * set `zend.exception_ignore_args=Off`, so exception traces carry arguments at all.
     */
    public function includeTraceArguments(): bool;

    /**
     * Last chance to redact a report. Applied to every channel the library writes to: `error_log()`,
     * rendered reports, the coding-agent prompt and debug-bar messages.
     */
    public function sanitizeErrorDetails(string $details): string;

    public function renderCriticalError(string $details, bool $echoOutput): never;

    public function renderNonCriticalError(string $label, string $details, bool $echoOutput): void;

    public function renderJavaScriptErrorHandler(): string;

    public function describeObject(object $value): ?string;
}
