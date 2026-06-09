<?php

declare(strict_types=1);

namespace voku\ErrorHandlerLib;

interface ErrorHandlerIntegrationInterface
{
    public function shouldEchoOutput(): bool;

    public function shouldDisplayErrors(): bool;

    public function isTestingEnvironment(): bool;

    public function isDebugBarRequest(): bool;

    public function addDebugBarMessage(int $errno, string $description): void;

    public function getGlobalInfo(): string;

    public function sanitizeErrorDetails(string $details): string;

    public function renderCriticalError(string $details, bool $echoOutput): never;

    public function renderNonCriticalError(string $label, string $details, bool $echoOutput): void;

    public function renderJavaScriptErrorHandler(): string;

    public function describeObject(object $value): ?string;
}
