<?php

declare(strict_types=1);

namespace voku\ErrorHandlerLib;

use Closure;
use ErrorException;
use LogicException;
use ReflectionException;
use ReflectionFunction;
use Throwable;

/**
 * @phpstan-type TraceLeaf scalar|resource|object|null
 * @phpstan-type TraceArrayValue TraceLeaf|array<array-key, TraceLeaf>
 * @phpstan-type TraceValue TraceLeaf|array<array-key, TraceArrayValue>
 * @phpstan-type TraceFrame array<string, TraceValue>
 * @phpstan-type TraceList list<TraceFrame>
 */
final class ErrorHandlerLib
{
    private const int MAX_ERRORS_IN_DEBUG_BAR = 50;
    private const int MAX_STR_LENGTH_FROM_TYPE_INFO = 256;
    private const int RESERVED_MEMORY_SIZE = 512;

    /**
     * Held only so it can be released in {@see handleFatalError()}, giving an out-of-memory fatal
     * enough headroom to be reported. It is never read by design.
     *
     * @phpstan-ignore property.onlyWritten
     */
    private static ?string $reservedMemory = null;

    private static ?ErrorHandlerIntegrationInterface $defaultIntegration = null;

    private static ?ErrorHandlerSkipDeciderInterface $defaultSkipDecider = null;

    private const int MAX_PREVIOUS_THROWABLES = 10;

    /**
     * Registered instances in registration order, keyed by object id.
     *
     * PHP runs shutdown functions in registration order and cannot remove one, so the library
     * registers a single dispatcher per process. It hands a fatal error to the most recently
     * registered instance only - the one whose error handler is active - and holds no reference to
     * an instance after {@see unregister()}, so per-job handlers in a worker can be collected.
     *
     * @var array<int, self>
     */
    private static array $registeredInstances = [];

    private static bool $shutdownDispatcherRegistered = false;

    /**
     * Error types that end the script, so `error_get_last()` can only hold one during shutdown.
     */
    private const int SCRIPT_TERMINATING_ERROR_TYPES = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

    /**
     * The fatal error as `error_get_last()` reported it before a later diagnostic replaced it.
     *
     * @var null|array<string, mixed>
     */
    private static ?array $pendingFatalError = null;

    private readonly int $fatalErrorTypes;

    private readonly ErrorHandlerIntegrationInterface $integration;

    private readonly ErrorHandlerSkipDeciderInterface $skipDecider;

    private ?Throwable $lastHandledThrowable = null;

    private int $noticeCounter = 0;

    private int $warningCounter = 0;

    private int $currentDebugBarErrorCount = 0;

    private bool $registered = false;

    private ?Closure $registeredErrorHandler = null;

    private ?Closure $registeredExceptionHandler = null;

    /**
     * ini values that {@see register()} actually changed, keyed by directive, holding the value
     * before the change, so {@see unregister()} can put back exactly those and nothing else.
     *
     * @var array<string, string>
     */
    private array $previousIniValues = [];

    /**
     * Set while register() asks the integration hooks, so a hook that calls register() again on this
     * instance cannot install a second handler pair.
     */
    private bool $registering = false;

    /**
     * Constructor arguments configure this instance only. They do not change the process-wide
     * defaults; use {@see useIntegration()} for that.
     */
    public function __construct(
        ?ErrorHandlerIntegrationInterface $integration = null,
        ?ErrorHandlerSkipDeciderInterface $skipDecider = null
    ) {
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

    /**
     * Reports a soft problem one severity level below {@see reportError()}.
     *
     * It deliberately raises `E_USER_DEPRECATED`, not `E_USER_WARNING`: `reportError()` already uses
     * `E_USER_WARNING` because `E_USER_ERROR` is deprecated as of PHP 8.4.
     */
    public static function reportWarning(string|Throwable $error): void
    {
        trigger_error(self::stringifyError($error), E_USER_DEPRECATED);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function handleException(Throwable $throwable, array $context = []): void
    {
        $this->handleThrowable($throwable, $context, $throwable->getTrace());
    }

    /**
     * @param array<string, scalar|null> $context
     * @param TraceList                  $backtrace
     */
    private function handleThrowable(Throwable $throwable, array $context, array $backtrace): void
    {
        $this->lastHandledThrowable = $throwable;

        // Not Throwable::__toString(): that renders argument values whenever the runtime keeps them,
        // bypassing includeTraceArguments(). It gets its own report section rather than a context
        // entry, because context values are truncated to a few hundred characters.
        // The chain is passed as a value: a diagnostic raised while this report is built (from
        // getGlobalInfo(), for example) must neither inherit nor clear it.
        $previous = $throwable->getPrevious();

        $this->handleCriticalDiagnostic(
            E_ERROR,
            self::displayClassName($throwable::class) . ': ' . $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine(),
            $context,
            $backtrace,
            $previous instanceof Throwable ? $this->describeThrowableChain($previous) : null
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
     * By default a PHP-suppressed non-critical diagnostic returns `false`, whatever the policy or the
     * skip decider decided. PHP's native handler then respects the suppression (nothing is displayed
     * or logged), but still records the diagnostic, so `error_get_last()` keeps working for code such
     * as `if (@fopen($path, 'rb') === false) { $error = error_get_last(); }`. An integration whose
     * {@see ErrorHandlerIntegrationInterface::recordSuppressedDiagnosticsInErrorGetLast()} returns
     * `false` gets `true` instead, and `error_get_last()` stays untouched. A critical diagnostic
     * never returns: step 1 hands it to the non-returning critical renderer, suppressed or not.
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
        self::rememberPendingFatalError();

        if (self::isCriticalDiagnostic($errno)) {
            $this->handleCriticalDiagnostic($errno, $errstr, $errfile, $errline, $context, $backtrace);
        }

        $renderingAllowed = true;
        $suppressedByPhp = self::isSuppressedByPhp($errno);

        if ($suppressedByPhp) {
            $policy = $this->integration->suppressedDiagnosticPolicy();

            if ($policy === SuppressedDiagnosticPolicy::Ignore) {
                return false;
            }

            $renderingAllowed = $policy !== SuppressedDiagnosticPolicy::LogOnly;
        }

        if ($this->skipDecider->shouldSkip($errno, $errstr, $errfile, $errline)) {
            return $suppressedByPhp ? !$this->integration->recordSuppressedDiagnosticsInErrorGetLast() : true;
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
                $this->integration->renderNonCriticalError(
                    'Warning',
                    $details . $this->debugPrompt($errno, $errstr, $errfile, $errline, $context, $backtrace),
                    $echoOutput
                );
            }
        } elseif ($this->integration->isTestingEnvironment()) {
            $details = $this->buildErrorDetails('PHP-NOTICE', $errno, $errstr, $errfile, $errline, $context, $backtrace);

            error_log($details);

            if ($echoOutput && $this->noticeCounter <= 1) {
                $this->noticeCounter++;
                $this->integration->renderNonCriticalError(
                    'Notice',
                    $details . $this->debugPrompt($errno, $errstr, $errfile, $errline, $context, $backtrace),
                    $echoOutput
                );
            }
        }

        if ($suppressedByPhp) {
            return !$this->integration->recordSuppressedDiagnosticsInErrorGetLast();
        }

        // Let PHP display it too exactly when PHP itself would: display_errors=stderr or =2 is on,
        // " on" (with whitespace) is off. See effectiveIniValue().
        if (self::effectiveIniValue('display_errors', (string) ini_get('display_errors')) !== 'off') {
            return false;
        }

        return true;
    }

    /**
     * Renders one diagnostic as a copy&paste-ready prompt for a coding agent.
     *
     * Rendered error reports already carry this. It is public so a host can put the same prompt
     * somewhere else - a debug bar panel, an issue template, a chat message - without rebuilding it.
     *
     * The result passes through the integration's sanitizer, exactly like the error details do.
     *
     * @param array<string, scalar|null> $context
     * @param null|TraceList             $backtrace
     */
    public function debugPrompt(
        int $errno,
        string $errstr,
        string $errfile = '',
        int $errline = 0,
        array $context = [],
        ?array $backtrace = null
    ): string {
        return $this->integration->sanitizeErrorDetails(
            ErrorHandlerDebugPrompt::build(
                $errno,
                $errstr,
                $errfile,
                $errline,
                self::isSuppressedByPhp($errno),
                $context,
                // PHP does not hand a trace to an error handler, so capture one when the caller has
                // none. Arguments are dropped at the source: the prompt never renders them.
                $backtrace ?? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
            )
        );
    }

    public function handleFatalError(): void
    {
        self::$reservedMemory = null;

        $error = error_get_last();
        if (
            self::$pendingFatalError !== null
            && (!is_array($error) || ((int) $error['type'] & self::SCRIPT_TERMINATING_ERROR_TYPES) === 0)
        ) {
            $error = self::$pendingFatalError;
        }

        if (!is_array($error)) {
            return;
        }

        if (!$this->shouldCaptureFatalError((int) $error['type'], (string) $error['message'])) {
            return;
        }

        $exception = new ErrorException(
            (string) $error['message'],
            E_ERROR,
            (int) $error['type'],
            (string) $error['file'],
            (int) $error['line']
        );

        $this->handleThrowable($exception, [], self::fatalErrorTrace($error) ?? $exception->getTrace());
    }

    /**
     * Shutdown functions registered before the library's dispatcher run first. If one of them raises
     * a diagnostic that this handler returns `false` for (a PHP-suppressed one, for example), PHP
     * records that diagnostic and `error_get_last()` no longer returns the fatal error. Keep the
     * fatal error before that can happen.
     *
     * Nothing is preserved if this handler is not active at that point, or if a shutdown function
     * calls `error_clear_last()` before raising the diagnostic or installs its own error handler.
     * Clearing after the copy was taken does not discard it.
     */
    private static function rememberPendingFatalError(): void
    {
        if (self::$pendingFatalError !== null) {
            return;
        }

        $last = error_get_last();
        if (
            is_array($last)
            && ((int) $last['type'] & self::SCRIPT_TERMINATING_ERROR_TYPES) !== 0
            && self::isRaisedOutsideTheScriptBody()
        ) {
            self::$pendingFatalError = $last;
        }
    }

    /**
     * A terminating error type can sit in `error_get_last()` without the script having ended:
     * `opcache_compile_file()` catches a compile bailout, raises a warning and returns `false`.
     * Keeping that compile error would replay it as a fatal error at shutdown.
     *
     * After a real fatal error only shutdown code runs, and a shutdown function is called by the
     * engine, not from a file: the outermost frame of its call stack has no `file`. Script code,
     * like the `opcache_compile_file()` call, always starts from a frame in a file.
     */
    private static function isRaisedOutsideTheScriptBody(): bool
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        $handlerFrame = null;
        foreach ($frames as $index => $frame) {
            if (($frame['class'] ?? null) === self::class && $frame['function'] === 'handleError') {
                $handlerFrame = $index;
            }
        }

        if ($handlerFrame === null || !isset($frames[$handlerFrame + 1])) {
            return false;
        }

        $outermost = $frames[array_key_last($frames)];

        return !isset($outermost['file']);
    }

    private function describeThrowableChain(Throwable $throwable): string
    {
        $showArgs = $this->integration->includeTraceArguments();
        $output = '';
        $depth = 0;

        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($depth >= self::MAX_PREVIOUS_THROWABLES) {
                $output .= "\n\n... (deeper previous throwables omitted)";

                break;
            }

            $output .= ($depth === 0 ? '' : "\n\nNext previous: ")
                . self::displayClassName($current::class) . ': ' . $current->getMessage()
                . ' in ' . $current->getFile() . ':' . $current->getLine()
                . "\nStack trace:";

            /** @var TraceList $trace */
            $trace = $current->getTrace();
            foreach ($trace as $frameNumber => $frame) {
                $output .= "\n" . $this->formatTraceFrame($frame, $frameNumber, $showArgs);
            }

            $depth++;
        }

        return $output;
    }

    /**
     * PHP 8.5+ can attach the backtrace of a fatal error to `error_get_last()` (`fatal_error_backtraces`).
     * Earlier versions have no such key; the shutdown-time trace is then the only one available.
     *
     * @param array<string, mixed> $error
     *
     * @return null|TraceList
     */
    private static function fatalErrorTrace(array $error): ?array
    {
        $trace = $error['trace'] ?? null;
        if (!is_array($trace) || $trace === [] || !array_is_list($trace)) {
            return null;
        }

        foreach ($trace as $frame) {
            if (!is_array($frame)) {
                return null;
            }
        }

        /** @var TraceList $trace */
        return $trace;
    }

    /**
     * @param TraceValue $value
     */
    public static function getTypeInfo($value, bool $showForWeb = true, int $internalCounter = 0): string
    {
        return self::formatValue($value, $showForWeb, $internalCounter, self::getDefaultIntegration());
    }

    /**
     * @param TraceValue $value
     */
    private static function formatValue(
        $value,
        bool $showForWeb,
        int $internalCounter,
        ErrorHandlerIntegrationInterface $integration
    ): string {
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
            return self::formatArrayValue($value, $showForWeb, $internalCounter, $integration);
        }

        if (is_object($value)) {
            return self::formatObjectValue($value, $integration);
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

    /**
     * Installs the error, exception and shutdown handlers. Calling it again on a registered
     * instance is a no-op.
     */
    public function register(): self
    {
        if ($this->registered || $this->registering) {
            return $this;
        }

        // Ask the integration first: if a hook throws, nothing has been changed yet and a retry
        // asks again. A hook that calls register() on this instance gets a no-op.
        $this->registering = true;

        try {
            $includeTraceArguments = $this->integration->includeTraceArguments();
            $displayErrors = $this->integration->shouldDisplayErrors();
        } finally {
            $this->registering = false;
        }

        self::registerShutdownDispatcher();
        $this->registerErrorHandler()
            ->registerExceptionHandler();

        $this->registered = true;
        self::$registeredInstances[spl_object_id($this)] = $this;

        if ($includeTraceArguments) {
            $this->setIni('zend.exception_ignore_args', 'Off');
        }

        if ($displayErrors) {
            $this->setIni('display_startup_errors', 'On');

            return $this;
        }

        $this->setIni('display_errors', 'Off');
        $this->setIni('display_startup_errors', 'Off');

        return $this;
    }

    /**
     * Reverts {@see register()}: restores the previous error and exception handlers (including the
     * error handler's original type mask), restores every ini value `register()` changed, and stops
     * this instance from receiving fatal errors at shutdown. The library keeps no reference to it.
     *
     * Handler stacks are LIFO. If another error or exception handler was installed after this one,
     * nothing is changed and a {@see LogicException} is thrown: popping the stack would remove the
     * other handler instead of this one.
     *
     * The check compares the active handler with the closure `register()` installed. It cannot detect
     * that same closure being installed again further up the stack (for example after a caller saved
     * the return value of `set_error_handler()` and re-installed it); `unregister()` would then pop
     * only the upper copy. Do not re-install the library's handler closures yourself.
     *
     * Only ini values that `register()` actually changed are restored. A directive `register()` left
     * alone is never written, so a value the host set after registration survives.
     */
    public function unregister(): self
    {
        if (!$this->registered) {
            return $this;
        }

        $currentErrorHandler = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        $currentExceptionHandler = set_exception_handler(static function (Throwable $throwable): void {
        });
        restore_exception_handler();

        if (
            $currentErrorHandler !== $this->registeredErrorHandler
            || $currentExceptionHandler !== $this->registeredExceptionHandler
        ) {
            throw new LogicException(
                'Cannot unregister ' . self::class . ': another error or exception handler was registered after it. Unregister that one first.'
            );
        }

        restore_error_handler();
        restore_exception_handler();

        foreach (array_reverse($this->previousIniValues, true) as $directive => $value) {
            ini_set($directive, $value);
        }

        unset(self::$registeredInstances[spl_object_id($this)]);
        $this->previousIniValues = [];
        $this->registeredErrorHandler = null;
        $this->registeredExceptionHandler = null;
        $this->registered = false;

        return $this;
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }

    /**
     * Changes a directive only if its effective value differs, and records the old value only if
     * the change took effect. A failed `ini_set()` (unknown directive, or one the runtime refuses
     * to change) leaves nothing to restore, so it is not recorded.
     */
    private function setIni(string $directive, string $value): void
    {
        $current = ini_get($directive);
        if ($current === false || self::iniValuesAreEquivalent($directive, $current, $value)) {
            return;
        }

        if (ini_set($directive, $value) === false) {
            return;
        }

        if (!array_key_exists($directive, $this->previousIniValues)) {
            $this->previousIniValues[$directive] = $current;
        }
    }

    /**
     * PHP stores a directive in the form it was given ("Off", "0", "", "2", " on" ...), so values
     * are compared by what PHP makes of them, not by their spelling.
     */
    private static function iniValuesAreEquivalent(string $directive, string $current, string $target): bool
    {
        return self::effectiveIniValue($directive, $current) === self::effectiveIniValue($directive, $target);
    }

    /**
     * Mirrors php-src: `php_get_display_errors_mode()` for display_errors (off, stdout or stderr) and
     * `zend_ini_parse_bool()` for the boolean directives - "true"/"yes"/"on" in any case, otherwise
     * the leading integer, so "2" is on and " on" (with whitespace) is off.
     */
    private static function effectiveIniValue(string $directive, string $value): string
    {
        $lower = strtolower($value);

        if ($directive === 'display_errors') {
            if (in_array($lower, ['on', 'yes', 'true', 'stdout'], true)) {
                return 'stdout';
            }

            if ($lower === 'stderr') {
                return 'stderr';
            }

            // php-src stores the numeric mode in a uint8_t: only the low byte counts, so 256 is off
            // and 258 is stderr.
            $mode = ((int) $value) & 0xFF;

            return match ($mode) {
                0 => 'off',
                2 => 'stderr',
                default => 'stdout',
            };
        }

        if (in_array($lower, ['on', 'yes', 'true'], true)) {
            return 'on';
        }

        return (int) $value !== 0 ? 'on' : 'off';
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
        ?array $backtrace,
        ?string $previousChain = null
    ): never {
        if ($this->integration->isDebugBarRequest()) {
            $this->addDebugBarError($errno, $errstr, $errfile, $errline, $context, $backtrace);
        }

        $details = $this->buildErrorDetails('PHP-ERROR', $errno, $errstr, $errfile, $errline, $context, $backtrace, $previousChain);

        // Logs stay machine-shaped; only the report a human is about to read gets the agent prompt.
        error_log($details);

        $echoOutput = $this->integration->shouldEchoOutput();
        if ($echoOutput) {
            $details .= $this->debugPrompt($errno, $errstr, $errfile, $errline, $context, $backtrace);
        }

        $this->integration->renderCriticalError($details, $echoOutput);
    }

    /**
     * A throwable is reduced to class, message and origin. Not `Throwable::__toString()`: that renders
     * the stack trace with argument values whenever the runtime keeps them. The handler that receives
     * the reported diagnostic adds its own trace under its own argument policy.
     */
    private static function stringifyError(string|Throwable $error): string
    {
        if (!$error instanceof Throwable) {
            return $error;
        }

        return self::displayClassName($error::class) . ': ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine();
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
            $description .= $this->backtrace($backtrace);

            if ($context !== []) {
                $description .= "\n" . $this->formatContext($context);
            }

            $this->integration->addDebugBarMessage($errno, $this->integration->sanitizeErrorDetails($description));
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
        ?array $backtrace,
        ?string $previousChain = null
    ): string {
        $details = "{$kind} {$errno} occurred in File {$errfile} on Line {$errline}\n\n";
        $details .= match ($kind) {
            'PHP-ERROR' => 'Error-Message : ',
            'PHP-WARNING' => 'Warning-Message : ',
            default => 'Notice-Message : ',
        };
        $details .= $errstr . "\n";
        $details .= $this->integration->getGlobalInfo();
        $details .= $this->backtrace($backtrace);

        if ($previousChain !== null) {
            $details .= "\nPrevious throwables (outermost first):\n" . $previousChain . "\n";
        }

        if ($context !== []) {
            $details .= "\n" . $this->formatContext($context);
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
                    'Uncaught ' . self::displayClassName($this->lastHandledThrowable::class) . ': ' . $this->lastHandledThrowable->getMessage()
                )
            ) {
                return false;
            }
        }

        return (bool) ($type & $this->fatalErrorTypes);
    }

    /**
     * Argument values are rendered only when the integration opts in via
     * {@see ErrorHandlerIntegrationInterface::includeTraceArguments()}: they are the part of a
     * trace most likely to carry credentials or personal data.
     *
     * @param null|TraceList $backtrace
     */
    private function backtrace(?array $backtrace = null): string
    {
        $showArgs = $this->integration->includeTraceArguments();
        $output = "\n\nStack trace:\n";
        $trace = $backtrace ?? debug_backtrace($showArgs ? 0 : DEBUG_BACKTRACE_IGNORE_ARGS);
        $frameNumber = 0;

        foreach ($trace as $frame) {
            if (($frame['class'] ?? null) === self::class) {
                continue;
            }

            $output .= $this->formatTraceFrame($frame, $frameNumber, $showArgs) . "\n";
            $frameNumber++;
        }

        return $output;
    }

    /**
     * @param TraceFrame $frame
     */
    private function formatTraceFrame(array $frame, int $frameNumber, bool $showArgs): string
    {
        $file = self::stringFrameValue($frame['file'] ?? '');
        if ($file === '') {
            $file = '[internal function]';
        }

        $line = self::stringFrameValue($frame['line'] ?? '');
        $location = $line === '' ? $file : $file . ':' . $line;

        $caller = self::displayClassName(self::stringFrameValue($frame['class'] ?? ''));
        $caller .= self::stringFrameValue($frame['type'] ?? '');
        $caller .= self::stringFrameValue($frame['function'] ?? '');
        if ($caller === '') {
            $caller = '[unknown]';
        }

        $args = '';
        if ($showArgs && isset($frame['args']) && is_array($frame['args'])) {
            $args = $this->formatArguments($frame['args']);
        }

        return sprintf('#%d %s %s(%s)', $frameNumber, $location, $caller, $args);
    }

    /**
     * @param array<int, TraceValue> $arguments
     */
    private function formatArguments(array $arguments): string
    {
        $formatted = [];

        foreach ($arguments as $argument) {
            $formatted[] = self::formatValue($argument, false, 0, $this->integration);
        }

        return implode(', ', $formatted);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function formatContext(array $context): string
    {
        $formatted = "Array\n(\n";

        foreach ($context as $name => $value) {
            $formatted .= ' [' . $name . '] => ' . self::formatValue($value, false, 0, $this->integration) . "\n";
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
    private static function formatArrayValue(
        array $value,
        bool $showForWeb,
        int $internalCounter,
        ErrorHandlerIntegrationInterface $integration
    ): string {
        $count = count($value);
        $text = 'Array(' . $count . ')';

        if ($count === 0) {
            return $text;
        }

        $parts = [];
        $position = 0;
        $hasMoreValues = false;
        $showAllValues = self::isNamedSqlParameterArray($value);

        foreach ($value as $key => $innerValue) {
            if (!$showAllValues && $position >= 5) {
                $hasMoreValues = true;

                break;
            }

            $formattedKey = is_string($key) ? "'" . $key . "'" : (string) $key;
            $parts[] = $formattedKey . ' => ' . self::formatValue($innerValue, $showForWeb, $internalCounter + 1, $integration);
            $position++;
        }

        if ($hasMoreValues) {
            $parts[] = '...';
        }

        return $text . ' {' . implode(' | ', $parts) . '} ';
    }

    /**
     * @param array<array-key, TraceValue> $value
     */
    private static function isNamedSqlParameterArray(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !str_starts_with($key, ':')) {
                return false;
            }
        }

        return true;
    }

    private static function formatObjectValue(object $value, ErrorHandlerIntegrationInterface $integration): string
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

        return $integration->describeObject($value) ?? ('Object(' . self::displayClassName($value::class) . ')');
    }

    private function registerExceptionHandler(): self
    {
        $this->registeredExceptionHandler = $this->handleException(...);
        set_exception_handler($this->registeredExceptionHandler);

        return $this;
    }

    private function registerErrorHandler(): self
    {
        $this->registeredErrorHandler = $this->handleError(...);
        set_error_handler($this->registeredErrorHandler, E_ALL);

        return $this;
    }

    /**
     * See {@see $registeredInstances}. The dispatcher is static so it never captures an instance.
     */
    private static function registerShutdownDispatcher(): void
    {
        if (!self::$shutdownDispatcherRegistered) {
            register_shutdown_function(static function (): void {
                $active = array_key_last(self::$registeredInstances);
                if ($active !== null) {
                    self::$registeredInstances[$active]->handleFatalError();
                }
            });
            self::$shutdownDispatcherRegistered = true;
        }

        self::$reservedMemory = str_repeat('x', self::RESERVED_MEMORY_SIZE);
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

    /**
     * An anonymous class name is "Parent@anonymous", a NUL byte, then its file and position. PHP
     * prints only the part before the NUL, and so must the library: error_log() stops writing at a
     * NUL byte, which silently dropped everything after such a name from the log entry.
     */
    private static function displayClassName(string $class): string
    {
        $nul = strpos($class, "\0");

        return $nul === false ? $class : substr($class, 0, $nul);
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
