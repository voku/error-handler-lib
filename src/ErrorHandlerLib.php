<?php

declare(strict_types=1);

namespace voku\ErrorHandlerLib;

use Closure;
use ErrorException;
use ReflectionException;
use ReflectionFunction;
use Throwable;

/**
 * @phpstan-type TraceValue scalar|resource|object|array<array-key, scalar|resource|object|null>|null
 * @phpstan-type TraceFrame array<string, TraceValue>
 * @phpstan-type TraceList list<TraceFrame>
 */
final class ErrorHandlerLib
{
    private const int MAX_ERRORS_IN_DEBUG_BAR = 50;
    private const int MAX_STR_LENGTH_FROM_TYPE_INFO = 256;
    private const int RESERVED_MEMORY_SIZE = 512;

    private static ?string $reservedMemory = null;

    private static ?ErrorHandlerIntegrationInterface $defaultIntegration = null;

    private static ?ErrorHandlerSkipDeciderInterface $defaultSkipDecider = null;

    private readonly int $fatalErrorTypes;

    private readonly ErrorHandlerIntegrationInterface $integration;

    private readonly ErrorHandlerSkipDeciderInterface $skipDecider;

    private ?Throwable $lastHandledThrowable = null;

    private int $noticeCounter = 0;

    private int $warningCounter = 0;

    private int $currentDebugBarErrorCount = 0;

    public function __construct(
        ?ErrorHandlerIntegrationInterface $integration = null,
        ?ErrorHandlerSkipDeciderInterface $skipDecider = null
    ) {
        if ($integration !== null) {
            self::$defaultIntegration = $integration;
        }

        if ($skipDecider !== null) {
            self::$defaultSkipDecider = $skipDecider;
        }

        $this->integration = $integration ?? self::getDefaultIntegration();
        $this->skipDecider = $skipDecider ?? self::getDefaultSkipDecider();
        $this->fatalErrorTypes = E_ERROR
            | E_PARSE
            | E_CORE_ERROR
            | E_CORE_WARNING
            | E_COMPILE_ERROR
            | E_COMPILE_WARNING;
    }

    public static function useIntegration(
        ErrorHandlerIntegrationInterface $integration,
        ?ErrorHandlerSkipDeciderInterface $skipDecider = null
    ): void {
        self::$defaultIntegration = $integration;
        self::$defaultSkipDecider = $skipDecider ?? new ErrorHandlerNoOpSkipDecider();
    }

    public static function resetDefaults(): void
    {
        self::$defaultIntegration = null;
        self::$defaultSkipDecider = null;
    }

    public static function reportError(string|Throwable $error): void
    {
        trigger_error(self::stringifyError($error), E_USER_WARNING);
    }

    public static function reportWarning(string|Throwable $error): void
    {
        trigger_error(self::stringifyError($error), E_USER_DEPRECATED);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function handleException(Throwable $throwable, array $context = []): void
    {
        $this->lastHandledThrowable = $throwable;

        $previous = $throwable->getPrevious();
        if ($previous instanceof Throwable) {
            $context['previous_error'] = ($context['previous_error'] ?? '') . $previous->__toString();
        }

        $this->handleError(
            E_ERROR,
            $throwable::class . ': ' . $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine(),
            $context,
            $throwable->getTrace()
        );
    }

    /**
     * Processes one diagnostic in a fixed, documented order:
     *
     *   1. critical/fatal diagnostics are handled unconditionally;
     *   2. PHP suppression is checked against the host's {@see SuppressedDiagnosticPolicy};
     *   3. the host's {@see ErrorHandlerSkipDeciderInterface} may drop the diagnostic;
     *   4. debug-bar, logging and rendering side effects run.
     *
     * Steps 2 and 3 apply to non-critical diagnostics only, so neither a suppression policy
     * nor a skip decider can hide a fatal error or an uncaught exception.
     *
     * @param array<string, scalar|null>     $context
     * @param null|TraceList          $backtrace
     */
    public function handleError(
        int $errno,
        string $errstr,
        string $errfile = '',
        int $errline = 0,
        array $context = [],
        ?array $backtrace = null
    ): bool {
        if (self::isCriticalDiagnostic($errno)) {
            $this->handleCriticalDiagnostic($errno, $errstr, $errfile, $errline, $context, $backtrace);
        }

        $renderingAllowed = true;

        if (self::isSuppressedByPhp($errno)) {
            $policy = $this->integration->suppressedDiagnosticPolicy();

            if ($policy === SuppressedDiagnosticPolicy::Ignore) {
                return false;
            }

            $renderingAllowed = $policy !== SuppressedDiagnosticPolicy::LogOnly;
        }

        if ($this->skipDecider->shouldSkip($errno, $errstr, $errfile, $errline)) {
            return true;
        }

        if ($this->integration->isDebugBarRequest()) {
            $this->addDebugBarError($errno, $errstr, $errfile, $errline, $context, $backtrace);
        }

        $echoOutput = $renderingAllowed && $this->integration->shouldEchoOutput();

        if (self::isWarningDiagnostic($errno)) {
            $details = $this->buildErrorDetails('PHP-WARNING', $errno, $errstr, $errfile, $errline, $context, $backtrace);

            error_log($details);

            if ($echoOutput && $this->warningCounter <= 1) {
                $this->warningCounter++;
                $this->integration->renderNonCriticalError('Warning', $details, $echoOutput);
            }
        } elseif ($this->integration->isTestingEnvironment()) {
            $details = $this->buildErrorDetails('PHP-NOTICE', $errno, $errstr, $errfile, $errline, $context, $backtrace);

            error_log($details);

            if ($echoOutput && $this->noticeCounter <= 1) {
                $this->noticeCounter++;
                $this->integration->renderNonCriticalError('Notice', $details, $echoOutput);
            }
        }

        if (filter_var((string) ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return true;
    }

    public function handleFatalError(): void
    {
        self::$reservedMemory = null;

        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }

        if (!$this->shouldCaptureFatalError((int) $error['type'], (string) $error['message'])) {
            return;
        }

        $this->handleException(
            new ErrorException(
                (string) $error['message'],
                E_ERROR,
                (int) $error['type'],
                (string) $error['file'],
                (int) $error['line']
            )
        );
    }

    /**
     * @param TraceValue $value
     */
    public static function getTypeInfo($value, bool $showForWeb = true, int $internalCounter = 0): string
    {
        if ($internalCounter > 50) {
            return 'TO_MANY_VALUES';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return '"' . self::formatStringValue($value, $showForWeb) . '"';
        }

        if (is_array($value)) {
            return self::formatArrayValue($value, $showForWeb, $internalCounter);
        }

        if (is_object($value)) {
            return self::formatObjectValue($value);
        }

        if (is_resource($value) || gettype($value) === 'resource (closed)') {
            return 'Resource(' . get_resource_type($value) . ')';
        }

        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }

        return 'Null';
    }

    public static function getGlobalJavaScriptErrorHandler(): string
    {
        return self::getDefaultIntegration()->renderJavaScriptErrorHandler();
    }

    public function register(): self
    {
        $this->registerShutdownFunction()
            ->registerErrorHandler()
            ->registerExceptionHandler();

        ini_set('zend.exception_ignore_args', 'Off');

        if ($this->integration->shouldDisplayErrors()) {
            ini_set('display_startup_errors', 'On');

            return $this;
        }

        ini_set('display_errors', 'Off');
        ini_set('display_startup_errors', 'Off');

        return $this;
    }

    /**
     * A diagnostic is critical when it is neither a notice, a warning, nor a deprecation.
     *
     * Critical diagnostics bypass suppression policy and skip filtering entirely.
     */
    private static function isCriticalDiagnostic(int $errno): bool
    {
        return ($errno & (E_ALL ^ (E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING | E_DEPRECATED | E_USER_DEPRECATED))) !== 0;
    }

    private static function isWarningDiagnostic(int $errno): bool
    {
        return ($errno & (E_ALL ^ (E_NOTICE | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED))) !== 0;
    }

    /**
     * Whether PHP itself excluded this diagnostic from the currently active reporting state.
     *
     * Inside an error handler `error_reporting()` still reflects the state that was active when
     * the diagnostic was raised, so this detects both `@expr` and an explicit reporting mask.
     */
    private static function isSuppressedByPhp(int $errno): bool
    {
        return (error_reporting() & $errno) === 0;
    }

    /**
     * @param array<string, scalar|null> $context
     * @param null|TraceList             $backtrace
     */
    private function handleCriticalDiagnostic(
        int $errno,
        string $errstr,
        string $errfile,
        int $errline,
        array $context,
        ?array $backtrace
    ): never {
        if ($this->integration->isDebugBarRequest()) {
            $this->addDebugBarError($errno, $errstr, $errfile, $errline, $context, $backtrace);
        }

        $details = $this->buildErrorDetails('PHP-ERROR', $errno, $errstr, $errfile, $errline, $context, $backtrace);

        error_log($details);

        $this->integration->renderCriticalError($details, $this->integration->shouldEchoOutput());
    }

    private static function stringifyError(string|Throwable $error): string
    {
        return $error instanceof Throwable ? $error->__toString() : $error;
    }

    /**
     * @param array<string, scalar|null>          $context
     * @param null|TraceList                   $backtrace
     */
    private function addDebugBarError(
        int $errno,
        string $errstr,
        string $errfile,
        int $errline,
        array $context,
        ?array $backtrace
    ): void {
        if ($this->currentDebugBarErrorCount < self::MAX_ERRORS_IN_DEBUG_BAR) {
            $description = 'ErrorNo:' . $errno . ' Error:' . $errstr . ' File:' . $errfile . ' Line:' . $errline . "\n\n";
            $description .= $this->backtrace($backtrace, true, true, false);

            if ($context !== []) {
                $description .= "\n" . self::formatContext($context);
            }

            $this->integration->addDebugBarMessage($errno, $description);
        }

        $this->currentDebugBarErrorCount++;
    }

    /**
     * @param array<string, scalar|null>          $context
     * @param null|TraceList                   $backtrace
     */
    private function buildErrorDetails(
        string $kind,
        int $errno,
        string $errstr,
        string $errfile,
        int $errline,
        array $context,
        ?array $backtrace
    ): string {
        $details = "{$kind} {$errno} occurred in File {$errfile} on Line {$errline}\n\n";
        $details .= match ($kind) {
            'PHP-ERROR' => 'Error-Message : ',
            'PHP-WARNING' => 'Warning-Message : ',
            default => 'Notice-Message : ',
        };
        $details .= $errstr . "\n";
        $details .= $this->integration->getGlobalInfo();
        $details .= $this->backtrace($backtrace, true, true, false);

        if ($context !== []) {
            $details .= "\n" . self::formatContext($context);
        }

        return $this->integration->sanitizeErrorDetails($details);
    }

    private function shouldCaptureFatalError(int $type, string $message): bool
    {
        if ($this->lastHandledThrowable instanceof Throwable) {
            if ($type === E_CORE_ERROR && self::startsWith($message, 'Exception thrown without a stack frame')) {
                return false;
            }

            if (
                $type === E_ERROR
                && self::startsWith(
                    $message,
                    'Uncaught ' . $this->lastHandledThrowable::class . ': ' . $this->lastHandledThrowable->getMessage()
                )
            ) {
                return false;
            }
        }

        return (bool) ($type & $this->fatalErrorTypes);
    }

    /**
     * @param null|TraceList $backtrace
     */
    private function backtrace(
        ?array $backtrace = null,
        bool $showArgs = true,
        bool $return = true,
        bool $showArgsFull = false
    ): string {
        $output = "\n\nStack trace:\n";
        $trace = $backtrace ?? debug_backtrace();
        $frameNumber = 0;

        if ($showArgs && $showArgsFull) {
            $output = "\n\nStack trace with full parameters:\n";
        }

        foreach ($trace as $frame) {
            if (($frame['class'] ?? null) === self::class) {
                continue;
            }

            $line = self::formatTraceFrame($frame, $frameNumber, $showArgs, $showArgsFull) . "\n";
            $frameNumber++;

            if ($return) {
                $output .= $line;
            } else {
                echo $line;
            }
        }

        return $return ? $output : '';
    }

    /**
     * @param TraceFrame $frame
     */
    private static function formatTraceFrame(array $frame, int $frameNumber, bool $showArgs, bool $showArgsFull): string
    {
        $file = self::stringFrameValue($frame['file'] ?? '');
        if ($file === '') {
            $file = '[internal function]';
        }

        $line = self::stringFrameValue($frame['line'] ?? '');
        $location = $line === '' ? $file : $file . ':' . $line;

        $caller = self::stringFrameValue($frame['class'] ?? '');
        $caller .= self::stringFrameValue($frame['type'] ?? '');
        $caller .= self::stringFrameValue($frame['function'] ?? '');
        if ($caller === '') {
            $caller = '[unknown]';
        }

        $args = '';
        if ($showArgs && isset($frame['args']) && is_array($frame['args'])) {
            $args = $showArgsFull ? trim(print_r($frame['args'], true)) : self::formatArguments($frame['args']);
        }

        return sprintf('#%d %s %s(%s)', $frameNumber, $location, $caller, $args);
    }

    /**
     * @param array<int, TraceValue> $arguments
     */
    private static function formatArguments(array $arguments): string
    {
        $formatted = [];

        foreach ($arguments as $argument) {
            $formatted[] = self::getTypeInfo($argument, false);
        }

        return implode(', ', $formatted);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private static function formatContext(array $context): string
    {
        $formatted = "Array\n(\n";

        foreach ($context as $name => $value) {
            $formatted .= ' [' . $name . '] => ' . self::getTypeInfo($value, false, 0) . "\n";
        }

        return $formatted . ")\n";
    }

    private static function formatStringValue(string $value, bool $showForWeb): string
    {
        if (!self::containsSqlKeyword($value)) {
            $value = substr($value, 0, self::MAX_STR_LENGTH_FROM_TYPE_INFO)
                . (strlen($value) > self::MAX_STR_LENGTH_FROM_TYPE_INFO ? '...' : '');
        }

        if ($showForWeb) {
            return htmlspecialchars($value, ENT_QUOTES);
        }

        return print_r($value, true);
    }

    /**
     * @param array<array-key, TraceValue> $value
     */
    private static function formatArrayValue(array $value, bool $showForWeb, int $internalCounter): string
    {
        $count = count($value);
        $text = 'Array(' . $count . ')';

        if ($count === 0) {
            return $text;
        }

        $parts = [];
        $position = 0;
        $hasMoreValues = false;

        foreach ($value as $key => $innerValue) {
            if ($position >= 5) {
                $hasMoreValues = true;

                break;
            }

            $formattedKey = is_string($key) ? "'" . $key . "'" : (string) $key;
            $parts[] = $formattedKey . ' => ' . self::getTypeInfo($innerValue, $showForWeb, $internalCounter + 1);
            $position++;
        }

        if ($hasMoreValues) {
            $parts[] = '...';
        }

        return $text . ' {' . implode(' | ', $parts) . '} ';
    }

    private static function formatObjectValue(object $value): string
    {
        if ($value instanceof Closure) {
            try {
                $closureReflection = new ReflectionFunction($value);

                return sprintf(
                    'Closure at %s:%s',
                    (string) $closureReflection->getFileName(),
                    $closureReflection->getStartLine()
                );
            } catch (ReflectionException) {
                return 'Closure';
            }
        }

        return self::getDefaultIntegration()->describeObject($value) ?? ('Object(' . $value::class . ')');
    }

    private function registerExceptionHandler(): self
    {
        set_exception_handler($this->handleException(...));

        return $this;
    }

    private function registerErrorHandler(): self
    {
        set_error_handler($this->handleError(...), E_ALL);

        return $this;
    }

    private function registerShutdownFunction(): self
    {
        register_shutdown_function([$this, 'handleFatalError']);

        if (self::$reservedMemory !== null) {
            self::$reservedMemory = null;
        }

        self::$reservedMemory = str_repeat('x', self::RESERVED_MEMORY_SIZE);

        return $this;
    }

    private static function getDefaultIntegration(): ErrorHandlerIntegrationInterface
    {
        if (self::$defaultIntegration === null) {
            self::$defaultIntegration = new ErrorHandlerDefaultIntegration();
        }

        return self::$defaultIntegration;
    }

    private static function getDefaultSkipDecider(): ErrorHandlerSkipDeciderInterface
    {
        if (self::$defaultSkipDecider === null) {
            self::$defaultSkipDecider = new ErrorHandlerNoOpSkipDecider();
        }

        return self::$defaultSkipDecider;
    }

    private static function containsSqlKeyword(string $value): bool
    {
        $upper = strtoupper($value);

        return str_contains($upper, 'SELECT ')
            || str_contains($upper, 'UPDATE ')
            || str_contains($upper, 'DELETE ')
            || str_contains($upper, 'INSERT ');
    }

    private static function startsWith(string $value, string $prefix): bool
    {
        return strncmp($value, $prefix, strlen($prefix)) === 0;
    }

    /**
     * @param TraceValue $value
     */
    private static function stringFrameValue($value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
