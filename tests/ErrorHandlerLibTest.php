<?php

declare(strict_types=1);

namespace voku\ErrorHandlerLib\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use voku\ErrorHandlerLib\ErrorHandlerIntegrationInterface;
use voku\ErrorHandlerLib\ErrorHandlerLib;
use voku\ErrorHandlerLib\ErrorHandlerNoOpSkipDecider;
use voku\ErrorHandlerLib\ErrorHandlerSkipDeciderInterface;
use voku\ErrorHandlerLib\SuppressedDiagnosticPolicy;

final class ErrorHandlerLibTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandlerLib::resetDefaults();
    }

    public function testReportErrorTriggersUserWarning(): void
    {
        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured = [$errno, $errstr];

            return true;
        });

        ErrorHandlerLib::reportError('critical path');
        restore_error_handler();

        self::assertSame([E_USER_WARNING, 'critical path'], $captured);
    }

    public function testReportWarningTriggersUserDeprecated(): void
    {
        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured = [$errno, $errstr];

            return true;
        });

        ErrorHandlerLib::reportWarning('deprecated path');
        restore_error_handler();

        self::assertSame([E_USER_DEPRECATED, 'deprecated path'], $captured);
    }

    public function testGetTypeInfoFormatsCommonValues(): void
    {
        self::assertSame('123', ErrorHandlerLib::getTypeInfo(123, false));
        self::assertSame('123.45', ErrorHandlerLib::getTypeInfo(123.45, false));
        self::assertSame('"test"', ErrorHandlerLib::getTypeInfo('test', false));
        self::assertSame('True', ErrorHandlerLib::getTypeInfo(true, false));
        self::assertSame('False', ErrorHandlerLib::getTypeInfo(false, false));
        self::assertSame('Null', ErrorHandlerLib::getTypeInfo(null, false));
        self::assertStringContainsString('Array(2)', ErrorHandlerLib::getTypeInfo([1, 2], false));
        self::assertSame('Object(stdClass)', ErrorHandlerLib::getTypeInfo(new stdClass(), false));
        self::assertStringContainsString('Closure', ErrorHandlerLib::getTypeInfo(static function (): void {
        }, false));
    }

    public function testGetTypeInfoFormatsArrayValuesWithoutTrailingSeparator(): void
    {
        self::assertSame(
            "Array(2) {'first' => 1 | 'second' => 2} ",
            ErrorHandlerLib::getTypeInfo(['first' => 1, 'second' => 2], false)
        );
    }

    public function testGetTypeInfoDoesNotEllipsizeArrayWithExactlyFiveValues(): void
    {
        self::assertSame(
            "Array(5) {'a' => 1 | 'b' => 2 | 'c' => 3 | 'd' => 4 | 'e' => 5} ",
            ErrorHandlerLib::getTypeInfo(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5], false)
        );
    }

    public function testGetTypeInfoEllipsizesArrayAfterFiveValues(): void
    {
        self::assertSame(
            "Array(6) {'a' => 1 | 'b' => 2 | 'c' => 3 | 'd' => 4 | 'e' => 5 | ...} ",
            ErrorHandlerLib::getTypeInfo(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5, 'f' => 6], false)
        );
    }

    public function testGetTypeInfoFormatsResourceValues(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);

        try {
            self::assertSame('Resource(stream)', ErrorHandlerLib::getTypeInfo($resource, false));
        } finally {
            fclose($resource);
        }
    }

    public function testGetTypeInfoFormatsClosedResourceValues(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);
        fclose($resource);

        self::assertSame('Resource(Unknown)', ErrorHandlerLib::getTypeInfo($resource, false));
    }

    public function testGetTypeInfoUsesInjectedObjectDescription(): void
    {
        ErrorHandlerLib::useIntegration(
            $this->createIntegration(
                describeObject: static fn (object $value): ?string => $value instanceof stdClass ? 'Injected(stdClass)' : null
            )
        );

        self::assertSame('Injected(stdClass)', ErrorHandlerLib::getTypeInfo(new stdClass(), false));
    }

    public function testGetGlobalJavaScriptErrorHandlerUsesIntegration(): void
    {
        ErrorHandlerLib::useIntegration(
            $this->createIntegration(
                renderJavaScriptErrorHandler: static fn (): string => '<script>customHandler();</script>'
            )
        );

        self::assertSame('<script>customHandler();</script>', ErrorHandlerLib::getGlobalJavaScriptErrorHandler());
    }

    public function testHandleErrorUsesInjectedSkipDecider(): void
    {
        $handler = new ErrorHandlerLib(
            $this->createIntegration(),
            new class() implements ErrorHandlerSkipDeciderInterface {
                public function shouldSkip(int $errno, string $errstr, string $errfile, int $errline): bool
                {
                    return true;
                }
            }
        );

        self::assertTrue($handler->handleError(E_USER_WARNING, 'skip me', '/tmp/test.php', 5));
    }

    public function testHandleErrorCapsDebugBarMessagesAtFifty(): void
    {
        $debugBarMessages = [];
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                isDebugBarRequest: static fn (): bool => true,
                addDebugBarMessage: static function (int $errno, string $description) use (&$debugBarMessages): void {
                    $debugBarMessages[] = [$errno, $description];
                }
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        for ($i = 0; $i < 55; $i++) {
            $handler->handleError(E_USER_NOTICE, 'debug notice ' . $i, '/tmp/debug.php', $i + 1);
        }

        self::assertCount(50, $debugBarMessages);
        self::assertStringContainsString('debug notice 49', $debugBarMessages[49][1]);
    }

    public function testHandleErrorRendersNoticeOnlyInTestingEnvironment(): void
    {
        $rendered = [];
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                shouldEchoOutput: static fn (): bool => true,
                isTestingEnvironment: static fn (): bool => true,
                renderNonCriticalError: static function (string $label, string $details, bool $echoOutput) use (&$rendered): void {
                    $rendered[] = [$label, $details, $echoOutput];
                }
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        self::assertTrue($handler->handleError(E_USER_NOTICE, 'testing notice', '/tmp/notice.php', 7));

        self::assertCount(1, $rendered);
        self::assertSame('Notice', $rendered[0][0]);
        self::assertStringContainsString('Notice-Message : testing notice', $rendered[0][1]);
        self::assertTrue($rendered[0][2]);
    }

    public function testHandleExceptionIncludesPreviousExceptionInRenderedContext(): void
    {
        $integration = $this->createIntegration(
            renderCriticalError: static function (string $details, bool $echoOutput): never {
                throw new RuntimeException($details);
            }
        );
        $handler = new ErrorHandlerLib($integration, new ErrorHandlerNoOpSkipDecider());
        $previous = new RuntimeException('previous failure');
        $throwable = new RuntimeException('current failure', 0, $previous);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RuntimeException: current failure');
        $this->expectExceptionMessage('previous_error');
        $this->expectExceptionMessage('RuntimeException: previous failure');

        $handler->handleException($throwable);
    }

    public function testHandleErrorRendersReadableStackTrace(): void
    {
        $checkoutRoot = dirname(__DIR__);
        $servicePath = $checkoutRoot . '/src/Service.php';
        $frontControllerPath = $checkoutRoot . '/public/index.php';
        $integration = $this->createIntegration(
            renderCriticalError: static function (string $details, bool $echoOutput): never {
                throw new RuntimeException($details);
            }
        );
        $handler = new ErrorHandlerLib($integration, new ErrorHandlerNoOpSkipDecider());

        try {
            $handler->handleError(
                E_USER_ERROR,
                'Boom',
                '/tmp/example.php',
                42,
                [],
                [
                    [
                        'file' => $servicePath,
                        'line' => 37,
                        'class' => 'App\\Service',
                        'type' => '->',
                        'function' => 'run',
                        'args' => ['job-42', 3, true],
                    ],
                    [
                        'file' => $frontControllerPath,
                        'line' => 12,
                        'function' => 'include',
                        'args' => [],
                    ],
                ]
            );

            self::fail('Expected critical error rendering to throw.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        self::assertStringContainsString(
            "Stack trace:\n#0 {$servicePath}:37 App\\Service->run(\"job-42\", 3, True)",
            $message
        );
        self::assertStringContainsString("#1 {$frontControllerPath}:12 include()", $message);
        self::assertStringNotContainsString('Called with params', $message);
        self::assertStringNotContainsString('Called By', $message);
        self::assertDoesNotMatchRegularExpression('/<[^>]+> \"[^\"]+\" on line: <\\d+>/', $message);
    }

    public function testHandleErrorDelegatesCriticalRenderingToIntegration(): void
    {
        $integration = $this->createIntegration(
            getGlobalInfo: static fn (): string => "GLOBAL\n",
            sanitizeErrorDetails: static fn (string $details): string => 'SAN:' . $details,
            renderCriticalError: static function (string $details, bool $echoOutput): never {
                throw new RuntimeException($details);
            }
        );
        $handler = new ErrorHandlerLib($integration, new ErrorHandlerNoOpSkipDecider());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SAN:PHP-ERROR 256 occurred in File /tmp/example.php on Line 42');

        $handler->handleError(E_USER_ERROR, 'Boom', '/tmp/example.php', 42, ['foo' => 'bar'], []);
    }

    public function testSuppressedWarningStaysObservableByDefault(): void
    {
        $rendered = [];
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                shouldEchoOutput: static fn (): bool => true,
                renderNonCriticalError: static function (string $label, string $details, bool $echoOutput) use (&$rendered): void {
                    $rendered[] = [$label, $details, $echoOutput];
                }
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        $log = $this->captureErrorLog(static function () use ($handler): void {
            set_error_handler($handler->handleError(...), E_ALL);

            try {
                // Deliberate `@` fixture: this is the developer-facing behavior the library exists for.
                @file_get_contents(__DIR__ . '/fixture-that-does-not-exist');
            } finally {
                restore_error_handler();
            }
        }, E_ALL);

        self::assertCount(1, $rendered, 'A PHP-suppressed warning must stay visible by default.');
        self::assertSame('Warning', $rendered[0][0]);
        self::assertStringContainsString('fixture-that-does-not-exist', $rendered[0][1]);
        self::assertStringContainsString('PHP-WARNING', $log);
    }

    public function testIgnorePolicyProducesNoObservableSideEffectsForSuppressedWarning(): void
    {
        $rendered = [];
        $debugBarMessages = [];
        $sanitizeCalls = 0;
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                sanitizeErrorDetails: static function (string $details) use (&$sanitizeCalls): string {
                    $sanitizeCalls++;

                    return $details;
                },
                shouldEchoOutput: static fn (): bool => true,
                isDebugBarRequest: static fn (): bool => true,
                addDebugBarMessage: static function (int $errno, string $description) use (&$debugBarMessages): void {
                    $debugBarMessages[] = [$errno, $description];
                },
                renderNonCriticalError: static function (string $label, string $details, bool $echoOutput) use (&$rendered): void {
                    $rendered[] = [$label, $details, $echoOutput];
                },
                suppressedDiagnosticPolicy: SuppressedDiagnosticPolicy::Ignore
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        $returned = null;
        $log = $this->captureErrorLog(static function () use ($handler, &$returned): void {
            set_error_handler(static function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use ($handler, &$returned): bool {
                $returned = $handler->handleError($errno, $errstr, $errfile, $errline);

                return true;
            }, E_ALL);

            try {
                @file_get_contents(__DIR__ . '/fixture-that-does-not-exist');
            } finally {
                restore_error_handler();
            }
        }, E_ALL);

        self::assertSame([], $rendered);
        self::assertSame([], $debugBarMessages);
        self::assertSame(0, $sanitizeCalls);
        self::assertSame('', $log);
        self::assertFalse($returned, 'Ignore defers to PHP instead of claiming the diagnostic was handled.');
    }

    public function testLogOnlyPolicyKeepsProcessingButSuppressesRendering(): void
    {
        $rendered = [];
        $debugBarMessages = [];
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                shouldEchoOutput: static fn (): bool => true,
                isDebugBarRequest: static fn (): bool => true,
                addDebugBarMessage: static function (int $errno, string $description) use (&$debugBarMessages): void {
                    $debugBarMessages[] = [$errno, $description];
                },
                renderNonCriticalError: static function (string $label, string $details, bool $echoOutput) use (&$rendered): void {
                    $rendered[] = [$label, $details, $echoOutput];
                },
                suppressedDiagnosticPolicy: SuppressedDiagnosticPolicy::LogOnly
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        $log = $this->captureErrorLog(static function () use ($handler): void {
            set_error_handler($handler->handleError(...), E_ALL);

            try {
                @file_get_contents(__DIR__ . '/fixture-that-does-not-exist');
            } finally {
                restore_error_handler();
            }
        }, E_ALL);

        self::assertSame([], $rendered, 'LogOnly must not render a suppressed diagnostic.');
        self::assertCount(1, $debugBarMessages);
        self::assertStringContainsString('PHP-WARNING', $log);
    }

    public function testUnsuppressedWarningIsUnaffectedByIgnorePolicy(): void
    {
        $rendered = [];
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                shouldEchoOutput: static fn (): bool => true,
                renderNonCriticalError: static function (string $label, string $details, bool $echoOutput) use (&$rendered): void {
                    $rendered[] = [$label, $details, $echoOutput];
                },
                suppressedDiagnosticPolicy: SuppressedDiagnosticPolicy::Ignore
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        $this->captureErrorLog(static function () use ($handler): void {
            set_error_handler($handler->handleError(...), E_ALL);

            try {
                file_get_contents(__DIR__ . '/fixture-that-does-not-exist');
            } finally {
                restore_error_handler();
            }
        }, E_ALL);

        self::assertCount(1, $rendered, 'The policy applies to suppressed diagnostics only.');
    }

    public function testSkippedDiagnosticProducesNoObservableSideEffects(): void
    {
        $rendered = [];
        $debugBarMessages = [];
        $sanitizeCalls = 0;
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                sanitizeErrorDetails: static function (string $details) use (&$sanitizeCalls): string {
                    $sanitizeCalls++;

                    return $details;
                },
                shouldEchoOutput: static fn (): bool => true,
                isTestingEnvironment: static fn (): bool => true,
                isDebugBarRequest: static fn (): bool => true,
                addDebugBarMessage: static function (int $errno, string $description) use (&$debugBarMessages): void {
                    $debugBarMessages[] = [$errno, $description];
                },
                renderNonCriticalError: static function (string $label, string $details, bool $echoOutput) use (&$rendered): void {
                    $rendered[] = [$label, $details, $echoOutput];
                }
            ),
            new class() implements ErrorHandlerSkipDeciderInterface {
                public function shouldSkip(int $errno, string $errstr, string $errfile, int $errline): bool
                {
                    return true;
                }
            }
        );

        $returned = null;
        $log = $this->captureErrorLog(static function () use ($handler, &$returned): void {
            $returned = $handler->handleError(E_USER_WARNING, 'skip me', '/tmp/test.php', 5);
        });

        self::assertTrue($returned, 'An application-skipped diagnostic stays fully handled.');
        self::assertSame([], $rendered);
        self::assertSame([], $debugBarMessages, 'Skip must run before the debug-bar side effect.');
        self::assertSame(0, $sanitizeCalls);
        self::assertSame('', $log);
    }

    public function testSkipDeciderIsNotConsultedForCriticalDiagnostics(): void
    {
        $skipDecider = new class() implements ErrorHandlerSkipDeciderInterface {
            public bool $consulted = false;

            public function shouldSkip(int $errno, string $errstr, string $errfile, int $errline): bool
            {
                $this->consulted = true;

                return true;
            }
        };
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                renderCriticalError: static function (string $details, bool $echoOutput): never {
                    throw new RuntimeException($details);
                }
            ),
            $skipDecider
        );

        try {
            $this->captureErrorLog(static function () use ($handler): void {
                $handler->handleError(E_USER_ERROR, 'Boom', '/tmp/example.php', 42);
            });

            self::fail('A critical diagnostic must never be skippable.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('PHP-ERROR', $exception->getMessage());
        }

        self::assertFalse($skipDecider->consulted);
    }

    public function testSuppressedDiagnosticPolicyIsNotConsultedForCriticalDiagnostics(): void
    {
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                renderCriticalError: static function (string $details, bool $echoOutput): never {
                    throw new RuntimeException($details);
                },
                suppressedDiagnosticPolicy: SuppressedDiagnosticPolicy::Ignore
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        $previousLevel = error_reporting(0);

        try {
            $this->captureErrorLog(static function () use ($handler): void {
                $handler->handleError(E_USER_ERROR, 'Boom', '/tmp/example.php', 42);
            });

            self::fail('A critical diagnostic must never be hidden by the suppression policy.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('PHP-ERROR', $exception->getMessage());
        } finally {
            error_reporting($previousLevel);
        }
    }

    public function testHandleExceptionSurvivesAnErrorReportingMaskThatExcludesErrors(): void
    {
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                renderCriticalError: static function (string $details, bool $echoOutput): never {
                    throw new RuntimeException($details);
                },
                suppressedDiagnosticPolicy: SuppressedDiagnosticPolicy::Ignore
            ),
            new class() implements ErrorHandlerSkipDeciderInterface {
                public function shouldSkip(int $errno, string $errstr, string $errfile, int $errline): bool
                {
                    return true;
                }
            }
        );

        $previousLevel = error_reporting(0);

        try {
            $this->captureErrorLog(static function () use ($handler): void {
                $handler->handleException(new RuntimeException('uncaught failure'));
            });

            self::fail('An uncaught exception must never disappear because of error_reporting().');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('RuntimeException: uncaught failure', $exception->getMessage());
        } finally {
            error_reporting($previousLevel);
        }
    }

    public function testUncaughtThrowableSurvivesTheMostHostileHostPolicy(): void
    {
        $output = $this->runFixture('uncaught-error-under-narrow-error-reporting.php');

        self::assertStringContainsString(
            "Failed opening required '",
            $output,
            'An uncaught throwable must survive Ignore policy, error_reporting(0) and a skip-everything decider.'
        );
        self::assertStringContainsString('ERROR ON CLI', $output);
    }

    public function testShutdownFatalErrorSurvivesTheMostHostileHostPolicy(): void
    {
        $output = $this->runFixture('fatal-shutdown-under-narrow-error-reporting.php');

        // PHP words the redeclaration fatal differently across 8.3 and 8.4+, so assert on the
        // invariant (the fatal reached critical rendering) rather than on PHP's phrasing.
        self::assertStringContainsString(
            'FixtureClassDeclaredTwice',
            $output,
            'A shutdown-detected fatal error must survive Ignore policy and a skip-everything decider.'
        );
        self::assertStringContainsString('PHP-ERROR', $output);
        self::assertStringContainsString('ERROR ON CLI', $output);
    }

    /**
     * Runs a fixture script in its own PHP process and returns its combined output.
     *
     * The fatal and shutdown paths cannot be exercised in-process, so they are proven end to end.
     */
    private function runFixture(string $fixtureName): string
    {
        $fixture = __DIR__ . '/Fixture/' . $fixtureName;
        self::assertFileExists($fixture);

        $process = proc_open(
            [PHP_BINARY, $fixture],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(1, proc_close($process), 'The fixture must exit through renderCriticalError().');

        return $stdout . $stderr;
    }

    /**
     * Runs $callback with `error_log()` redirected into a temporary file and returns what was written.
     *
     * $errorReporting pins the reporting level for the duration of the callback. Tests that exercise
     * `@` need `E_ALL` here: PHPUnit narrows `error_reporting()` to a fatal-only mask while a test
     * runs, which would otherwise make every warning look PHP-suppressed and let the assertion pass
     * for the wrong reason.
     */
    private function captureErrorLog(Closure $callback, ?int $errorReporting = null): string
    {
        $logFile = tempnam(sys_get_temp_dir(), 'error-handler-lib-');
        self::assertIsString($logFile);

        $previousDestination = (string) ini_get('error_log');
        $previousLogErrors = (string) ini_get('log_errors');
        ini_set('error_log', $logFile);
        ini_set('log_errors', '1');

        $previousLevel = $errorReporting === null ? null : error_reporting($errorReporting);

        try {
            $callback();
        } finally {
            if ($previousLevel !== null) {
                error_reporting($previousLevel);
            }

            ini_set('error_log', $previousDestination);
            ini_set('log_errors', $previousLogErrors);
        }

        $written = (string) file_get_contents($logFile);
        unlink($logFile);

        return $written;
    }

    private function createIntegration(
        ?Closure $describeObject = null,
        ?Closure $renderJavaScriptErrorHandler = null,
        ?Closure $getGlobalInfo = null,
        ?Closure $sanitizeErrorDetails = null,
        ?Closure $renderCriticalError = null,
        ?Closure $shouldEchoOutput = null,
        ?Closure $isTestingEnvironment = null,
        ?Closure $isDebugBarRequest = null,
        ?Closure $addDebugBarMessage = null,
        ?Closure $renderNonCriticalError = null,
        SuppressedDiagnosticPolicy $suppressedDiagnosticPolicy = SuppressedDiagnosticPolicy::Observe
    ): ErrorHandlerIntegrationInterface {
        return new class($describeObject, $renderJavaScriptErrorHandler, $getGlobalInfo, $sanitizeErrorDetails, $renderCriticalError, $shouldEchoOutput, $isTestingEnvironment, $isDebugBarRequest, $addDebugBarMessage, $renderNonCriticalError, $suppressedDiagnosticPolicy) implements ErrorHandlerIntegrationInterface {
            public function __construct(
                private readonly ?Closure $describeObject,
                private readonly ?Closure $renderJavaScriptErrorHandler,
                private readonly ?Closure $getGlobalInfo,
                private readonly ?Closure $sanitizeErrorDetails,
                private readonly ?Closure $renderCriticalError,
                private readonly ?Closure $shouldEchoOutput,
                private readonly ?Closure $isTestingEnvironment,
                private readonly ?Closure $isDebugBarRequest,
                private readonly ?Closure $addDebugBarMessage,
                private readonly ?Closure $renderNonCriticalError,
                private readonly SuppressedDiagnosticPolicy $suppressedDiagnosticPolicy
            ) {
            }

            public function suppressedDiagnosticPolicy(): SuppressedDiagnosticPolicy
            {
                return $this->suppressedDiagnosticPolicy;
            }

            public function shouldEchoOutput(): bool
            {
                return $this->shouldEchoOutput ? (bool) ($this->shouldEchoOutput)() : false;
            }

            public function shouldDisplayErrors(): bool
            {
                return false;
            }

            public function isTestingEnvironment(): bool
            {
                return $this->isTestingEnvironment ? (bool) ($this->isTestingEnvironment)() : false;
            }

            public function isDebugBarRequest(): bool
            {
                return $this->isDebugBarRequest ? (bool) ($this->isDebugBarRequest)() : false;
            }

            public function addDebugBarMessage(int $errno, string $description): void
            {
                if ($this->addDebugBarMessage) {
                    ($this->addDebugBarMessage)($errno, $description);
                }
            }

            public function getGlobalInfo(): string
            {
                return $this->getGlobalInfo ? ($this->getGlobalInfo)() : '';
            }

            public function sanitizeErrorDetails(string $details): string
            {
                return $this->sanitizeErrorDetails ? ($this->sanitizeErrorDetails)($details) : $details;
            }

            public function renderCriticalError(string $details, bool $echoOutput): never
            {
                if ($this->renderCriticalError) {
                    ($this->renderCriticalError)($details, $echoOutput);
                }

                throw new RuntimeException($details);
            }

            public function renderNonCriticalError(string $label, string $details, bool $echoOutput): void
            {
                if ($this->renderNonCriticalError) {
                    ($this->renderNonCriticalError)($label, $details, $echoOutput);
                }
            }

            public function renderJavaScriptErrorHandler(): string
            {
                return $this->renderJavaScriptErrorHandler ? ($this->renderJavaScriptErrorHandler)() : '';
            }

            public function describeObject(object $value): ?string
            {
                return $this->describeObject ? ($this->describeObject)($value) : null;
            }
        };
    }
}
