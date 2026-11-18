<?php

declare(strict_types=1);

namespace voku\ErrorHandlerLib\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;
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

    public function testGetTypeInfoDoesNotEllipsizeNamedSqlParameters(): void
    {
        self::assertSame(
            "Array(6) {':server' => \"test-server\" | ':company_group' => 10 | ':company' => 831 | ':account' => \"TESTUSER\" | ':display_name' => \"Test User\" | ':level' => 1} ",
            ErrorHandlerLib::getTypeInfo([
                ':server'        => 'test-server',
                ':company_group' => 10,
                ':company'       => 831,
                ':account'       => 'TESTUSER',
                ':display_name'  => 'Test User',
                ':level'         => 1,
            ], false)
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

        self::assertTrue($this->withErrorReporting(E_ALL, static fn (): bool => $handler->handleError(E_USER_WARNING, 'skip me', '/tmp/test.php', 5)));
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

        self::assertTrue($this->withErrorReporting(E_ALL, static fn (): bool => $handler->handleError(E_USER_NOTICE, 'testing notice', '/tmp/notice.php', 7)));

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

        try {
            $handler->handleException($throwable);
            self::fail('Expected critical rendering to throw.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        // Three separate assertions: expectExceptionMessage() keeps only the last one.
        self::assertStringContainsString('RuntimeException: current failure', $message);
        self::assertStringContainsString('Previous throwables (outermost first):', $message);
        self::assertStringContainsString('RuntimeException: previous failure', $message);
    }

    public function testHandleErrorRendersReadableStackTrace(): void
    {
        $checkoutRoot = dirname(__DIR__);
        $servicePath = $checkoutRoot . '/src/Service.php';
        $frontControllerPath = $checkoutRoot . '/public/index.php';
        $integration = $this->createIntegration(
            renderCriticalError: static function (string $details, bool $echoOutput): never {
                throw new RuntimeException($details);
            },
            includeTraceArguments: true
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

    public function testHandleErrorIncludesAllNamedSqlParametersInStackTrace(): void
    {
        $integration = $this->createIntegration(
            renderCriticalError: static function (string $details, bool $echoOutput): never {
                throw new RuntimeException($details);
            },
            includeTraceArguments: true
        );
        $handler = new ErrorHandlerLib($integration, new ErrorHandlerNoOpSkipDecider());

        try {
            $handler->handleError(
                E_USER_ERROR,
                'Database query failed',
                '/tmp/example.php',
                42,
                [],
                [
                    [
                        'file'     => '/tmp/Database.php',
                        'line'     => 123,
                        'class'    => 'App\\Database',
                        'type'     => '->',
                        'function' => 'executeSqlOrThrowException',
                        'args'     => [
                            'CALL address_manager(:server, :company_group, :company, :account, :display_name, :level)',
                            [
                                ':server'        => 'test-server',
                                ':company_group' => 10,
                                ':company'       => 831,
                                ':account'       => 'TESTUSER',
                                ':display_name'  => 'Test User',
                                ':level'         => 1,
                            ],
                        ],
                    ],
                ]
            );

            self::fail('Expected critical error rendering to throw.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        self::assertStringContainsString("':display_name' => \"Test User\"", $message);
        self::assertStringContainsString("':level' => 1", $message);
        self::assertStringNotContainsString("':display_name' => \"Test User\" | ...", $message);
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
        }, E_ALL);

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

    public function testDebugPromptSaysWhenPhpHadHiddenTheDiagnostic(): void
    {
        $handler = new ErrorHandlerLib($this->createIntegration(), new ErrorHandlerNoOpSkipDecider());

        $previousLevel = error_reporting(E_ALL & ~E_USER_WARNING);

        try {
            $prompt = $handler->debugPrompt(E_USER_WARNING, 'hidden failure', '/app/src/Loader.php', 42);
        } finally {
            error_reporting($previousLevel);
        }

        self::assertStringContainsString('E_USER_WARNING (512)', $prompt);
        self::assertStringContainsString('would normally have been invisible', $prompt);
        self::assertStringContainsString('Do not restore the suppression.', $prompt);
        self::assertStringContainsString('/app/src/Loader.php:42', $prompt);
    }

    public function testDebugPromptSaysWhenTheDiagnosticWasReportedNormally(): void
    {
        $handler = new ErrorHandlerLib($this->createIntegration(), new ErrorHandlerNoOpSkipDecider());

        $previousLevel = error_reporting(E_ALL);

        try {
            $prompt = $handler->debugPrompt(E_USER_WARNING, 'plain failure', '/app/src/Loader.php', 42);
        } finally {
            error_reporting($previousLevel);
        }

        self::assertStringContainsString('reported normally by PHP', $prompt);
        self::assertStringNotContainsString('would normally have been invisible', $prompt);
        self::assertStringContainsString('Do not silence it with `@`', $prompt);
    }

    public function testDebugPromptIsDeterministic(): void
    {
        $handler = new ErrorHandlerLib($this->createIntegration(), new ErrorHandlerNoOpSkipDecider());
        $backtrace = [['file' => '/app/src/Loader.php', 'line' => 42, 'class' => 'App\\Loader', 'type' => '->', 'function' => 'read']];

        $first = $handler->debugPrompt(E_USER_WARNING, 'same failure', '/app/src/Loader.php', 42, ['job' => 'nightly'], $backtrace);
        $second = $handler->debugPrompt(E_USER_WARNING, 'same failure', '/app/src/Loader.php', 42, ['job' => 'nightly'], $backtrace);

        self::assertSame($first, $second, 'The same diagnostic must render byte-identical prompts.');
    }

    public function testDebugPromptRendersTheCallPathWithoutArgumentValues(): void
    {
        $handler = new ErrorHandlerLib($this->createIntegration(), new ErrorHandlerNoOpSkipDecider());

        $prompt = $handler->debugPrompt(
            E_USER_WARNING,
            'connect failed',
            '/app/src/Loader.php',
            42,
            [],
            [
                [
                    'file' => '/app/src/Loader.php',
                    'line' => 42,
                    'class' => 'App\\Loader',
                    'type' => '->',
                    'function' => 'connect',
                    'args' => ['db-user', 'hunter2-should-never-be-rendered'],
                ],
                ['file' => '/app/public/index.php', 'line' => 12, 'function' => 'include'],
            ]
        );

        self::assertStringContainsString('1. /app/src/Loader.php:42 App\\Loader->connect()', $prompt);
        self::assertStringContainsString('2. /app/public/index.php:12 include()', $prompt);
        self::assertStringNotContainsString('hunter2-should-never-be-rendered', $prompt);
    }

    public function testDebugPromptOmitsTheHandlersOwnFrames(): void
    {
        $handler = new ErrorHandlerLib($this->createIntegration(), new ErrorHandlerNoOpSkipDecider());

        $prompt = $handler->debugPrompt(
            E_USER_WARNING,
            'noise',
            '/app/src/Loader.php',
            42,
            [],
            [
                ['file' => '/app/src/Loader.php', 'line' => 42, 'class' => ErrorHandlerLib::class, 'type' => '->', 'function' => 'handleError'],
                ['file' => '/app/src/Loader.php', 'line' => 42, 'class' => 'App\\Loader', 'type' => '->', 'function' => 'read'],
            ]
        );

        self::assertStringNotContainsString('handleError()', $prompt);
        self::assertStringContainsString('1. /app/src/Loader.php:42 App\\Loader->read()', $prompt);
    }

    public function testDebugPromptPassesThroughTheIntegrationSanitizer(): void
    {
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                sanitizeErrorDetails: static fn (string $details): string => str_replace('s3cr3t', '[redacted]', $details)
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        $prompt = $handler->debugPrompt(E_USER_WARNING, 'token s3cr3t rejected', '/app/src/Loader.php', 42);

        self::assertStringNotContainsString('s3cr3t', $prompt);
        self::assertStringContainsString('[redacted]', $prompt);
    }

    public function testRenderedWarningReportCarriesThePromptButTheLogDoesNot(): void
    {
        $rendered = [];
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                shouldEchoOutput: static fn (): bool => true,
                renderNonCriticalError: static function (string $label, string $details, bool $echoOutput) use (&$rendered): void {
                    $rendered[] = $details;
                }
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        $log = $this->captureErrorLog(static function () use ($handler): void {
            $handler->handleError(E_USER_WARNING, 'render me', '/app/src/Loader.php', 42);
        }, E_ALL);

        self::assertCount(1, $rendered);
        self::assertStringContainsString('Warning-Message : render me', $rendered[0]);
        self::assertStringContainsString('COPY EVERYTHING BELOW INTO A CODING AGENT', $rendered[0]);

        self::assertStringContainsString('render me', $log);
        self::assertStringNotContainsString(
            'COPY EVERYTHING BELOW INTO A CODING AGENT',
            $log,
            'Logs stay machine-shaped; the agent prompt is for the report a human reads.'
        );
    }

    public function testNoPromptIsRenderedWhenTheHostDoesNotEchoOutput(): void
    {
        $rendered = [];
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                shouldEchoOutput: static fn (): bool => false,
                renderNonCriticalError: static function (string $label, string $details, bool $echoOutput) use (&$rendered): void {
                    $rendered[] = $details;
                }
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        $this->captureErrorLog(static function () use ($handler): void {
            $handler->handleError(E_USER_WARNING, 'quiet', '/app/src/Loader.php', 42);
        }, E_ALL);

        self::assertSame([], $rendered);
    }

    public function testCriticalReportCarriesThePromptWhenOutputIsEchoed(): void
    {
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                shouldEchoOutput: static fn (): bool => true,
                renderCriticalError: static function (string $details, bool $echoOutput): never {
                    throw new RuntimeException($details);
                }
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        try {
            $this->captureErrorLog(static function () use ($handler): void {
                $handler->handleException(new RuntimeException('boom'));
            }, E_ALL);

            self::fail('Expected critical rendering to throw.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('RuntimeException: boom', $exception->getMessage());
            self::assertStringContainsString('COPY EVERYTHING BELOW INTO A CODING AGENT', $exception->getMessage());
        }
    }

    public function testStackTraceOmitsArgumentValuesByDefault(): void
    {
        $handler = new ErrorHandlerLib($this->createIntegration(), new ErrorHandlerNoOpSkipDecider());

        try {
            $handler->handleError(E_USER_ERROR, 'Login failed', '/tmp/auth.php', 9, [], [
                [
                    'file'     => '/tmp/Auth.php',
                    'line'     => 21,
                    'class'    => 'App\\Auth',
                    'type'     => '->',
                    'function' => 'login',
                    'args'     => ['admin', 'hunter2-secret'],
                ],
            ]);

            self::fail('Expected critical error rendering to throw.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        self::assertStringContainsString('#0 /tmp/Auth.php:21 App\\Auth->login()', $message);
        self::assertStringNotContainsString('hunter2-secret', $message);
    }

    public function testDebugBarMessagePassesThroughTheIntegrationSanitizer(): void
    {
        $debugBarMessages = [];
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                sanitizeErrorDetails: static fn (string $details): string => str_replace('hunter2', '[REDACTED]', $details),
                isDebugBarRequest: static fn (): bool => true,
                addDebugBarMessage: static function (int $errno, string $description) use (&$debugBarMessages): void {
                    $debugBarMessages[] = $description;
                }
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        $this->captureErrorLog(static function () use ($handler): void {
            $handler->handleError(E_USER_WARNING, 'password hunter2 rejected', '/tmp/test.php', 5, ['token' => 'hunter2']);
        }, E_ALL);

        self::assertCount(1, $debugBarMessages);
        self::assertStringNotContainsString('hunter2', $debugBarMessages[0]);
        self::assertStringContainsString('[REDACTED]', $debugBarMessages[0]);
    }

    public function testConstructorInjectionDoesNotChangeProcessWideDefaults(): void
    {
        new ErrorHandlerLib(
            $this->createIntegration(
                describeObject: static fn (object $value): string => 'Injected(' . $value::class . ')',
                renderJavaScriptErrorHandler: static fn (): string => '<script>injected</script>'
            )
        );

        self::assertSame('Object(stdClass)', ErrorHandlerLib::getTypeInfo(new stdClass(), false));
        self::assertSame('', ErrorHandlerLib::getGlobalJavaScriptErrorHandler());
    }

    public function testInjectedIntegrationDescribesObjectsInItsOwnTraces(): void
    {
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                describeObject: static fn (object $value): string => 'Injected(' . $value::class . ')',
                includeTraceArguments: true
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        try {
            $handler->handleError(E_USER_ERROR, 'Boom', '/tmp/x.php', 1, [], [
                ['file' => '/tmp/y.php', 'line' => 2, 'function' => 'run', 'args' => [new stdClass()]],
            ]);

            self::fail('Expected critical error rendering to throw.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('run(Injected(stdClass))', $exception->getMessage());
        }
    }

    /**
     * Native PHP semantics: a handler that returns `true` for an `@`-suppressed diagnostic hides it
     * from `error_get_last()`. Observing a diagnostic must not change what the application sees.
     */
    public function testObservedSuppressedDiagnosticStaysVisibleToErrorGetLast(): void
    {
        foreach ([SuppressedDiagnosticPolicy::Observe, SuppressedDiagnosticPolicy::LogOnly, SuppressedDiagnosticPolicy::Ignore] as $policy) {
            $handler = new ErrorHandlerLib(
                $this->createIntegration(suppressedDiagnosticPolicy: $policy),
                new ErrorHandlerNoOpSkipDecider()
            );

            $lastError = $this->withErrorReporting(E_ALL, function () use ($handler): ?array {
                error_clear_last();
                set_error_handler($handler->handleError(...), E_ALL);

                try {
                    $this->captureErrorLog(static function (): void {
                        @trigger_error('suppressed for ' . __FUNCTION__, E_USER_WARNING);
                    }, E_ALL);
                } finally {
                    restore_error_handler();
                }

                return error_get_last();
            });

            self::assertIsArray($lastError, $policy->name . ' must keep error_get_last() working.');
            self::assertSame(E_USER_WARNING, $lastError['type']);
        }
    }

    public function testSkippedSuppressedDiagnosticStaysVisibleToErrorGetLast(): void
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

        self::assertFalse($this->withErrorReporting(0, static fn (): bool => $handler->handleError(E_USER_WARNING, 'skip me', '/tmp/test.php', 5)));
    }

    public function testRegisterIsIdempotentAndUnregisterRestoresThePreviousHandlerWithItsMask(): void
    {
        $this->withRestoredHandlerState(function (Closure $track): void {
            $previousHandler = static fn (): bool => true;
            set_error_handler($previousHandler, E_USER_NOTICE);
            ini_set('display_errors', '1');

            $handler = $track(new ErrorHandlerLib($this->createIntegration(), new ErrorHandlerNoOpSkipDecider()));
            $handler->register();
            $handler->register();
            self::assertTrue($handler->isRegistered());
            self::assertFalse(filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN));

            $handler->unregister();
            self::assertFalse($handler->isRegistered());
            self::assertSame('1', (string) ini_get('display_errors'));
            self::assertSame($previousHandler, self::currentErrorHandler(), 'A second register() must not stack a second handler.');
            self::assertPreviousHandlerKeptItsUserNoticeMask();
        });
    }

    public function testRegisterLeavesTraceArgumentsAloneWithoutOptIn(): void
    {
        $this->withRestoredHandlerState(function (Closure $track): void {
            ini_set('zend.exception_ignore_args', '1');

            $handler = $track(new ErrorHandlerLib($this->createIntegration(), new ErrorHandlerNoOpSkipDecider()))->register();
            self::assertSame('1', (string) ini_get('zend.exception_ignore_args'));
            $handler->unregister();
        });
    }

    public function testUnregisterRefusesToPopAnErrorHandlerRegisteredAfterIt(): void
    {
        $this->withRestoredHandlerState(function (Closure $track): void {
            $handler = $track(new ErrorHandlerLib($this->createIntegration(), new ErrorHandlerNoOpSkipDecider()))->register();
            $later = static fn (): bool => true;
            set_error_handler($later);

            try {
                $handler->unregister();
                self::fail('Expected unregister() to refuse.');
            } catch (\LogicException) {
                self::assertTrue($handler->isRegistered());
                self::assertSame($later, self::currentErrorHandler());
            }

            restore_error_handler();
            $handler->unregister();
            self::assertFalse($handler->isRegistered());
        });
    }

    public function testUnregisterRestoresThePreviousExceptionHandler(): void
    {
        $this->withRestoredHandlerState(function (Closure $track): void {
            $previousExceptionHandler = static function (\Throwable $throwable): void {
            };
            set_exception_handler($previousExceptionHandler);

            $handler = $track(new ErrorHandlerLib($this->createIntegration(), new ErrorHandlerNoOpSkipDecider()))->register();
            self::assertNotSame($previousExceptionHandler, self::currentExceptionHandler());

            $handler->unregister();
            self::assertSame($previousExceptionHandler, self::currentExceptionHandler());
        });
    }

    public function testUnregisterRefusesToPopAnExceptionHandlerRegisteredAfterIt(): void
    {
        $this->withRestoredHandlerState(function (Closure $track): void {
            $handler = $track(new ErrorHandlerLib($this->createIntegration(), new ErrorHandlerNoOpSkipDecider()))->register();
            $libraryErrorHandler = self::currentErrorHandler();
            $later = static function (\Throwable $throwable): void {
            };
            set_exception_handler($later);

            try {
                $handler->unregister();
                self::fail('Expected unregister() to refuse.');
            } catch (\LogicException) {
                self::assertTrue($handler->isRegistered());
                self::assertSame($later, self::currentExceptionHandler());
                self::assertSame($libraryErrorHandler, self::currentErrorHandler(), 'Nothing may be popped on refusal.');
            }

            restore_exception_handler();
            $handler->unregister();
        });
    }

    public function testOptingIntoTraceArgumentsEnablesExceptionArgumentsUntilUnregistered(): void
    {
        $this->withRestoredHandlerState(function (Closure $track): void {
            ini_set('zend.exception_ignore_args', 'On');

            $handler = $track(new ErrorHandlerLib($this->createIntegration(includeTraceArguments: true), new ErrorHandlerNoOpSkipDecider()))->register();
            self::assertFalse(filter_var(ini_get('zend.exception_ignore_args'), FILTER_VALIDATE_BOOLEAN));

            $handler->unregister();
            self::assertSame('On', (string) ini_get('zend.exception_ignore_args'));
        });
    }

    /**
     * register() found both directives already at its target values, so it changed nothing and
     * must not write its snapshot back over what the host decided after registration.
     */
    public function testUnregisterKeepsIniChangesTheLibraryDidNotMake(): void
    {
        $this->withRestoredHandlerState(function (Closure $track): void {
            ini_set('zend.exception_ignore_args', '0');
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');

            $handler = $track(new ErrorHandlerLib($this->createIntegration(includeTraceArguments: true), new ErrorHandlerNoOpSkipDecider()))->register();
            self::assertSame('0', (string) ini_get('zend.exception_ignore_args'), 'Equivalent values are not rewritten.');
            self::assertSame('0', (string) ini_get('display_errors'));

            ini_set('zend.exception_ignore_args', 'On');
            ini_set('display_errors', 'stderr');

            $handler->unregister();
            self::assertSame('On', (string) ini_get('zend.exception_ignore_args'));
            self::assertSame('stderr', (string) ini_get('display_errors'));
        });
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRegistrationHooks(): iterable
    {
        yield 'includeTraceArguments() throws' => ['includeTraceArguments'];
        yield 'shouldDisplayErrors() throws' => ['shouldDisplayErrors'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideRegistrationHooks')]
    public function testRegisterChangesNothingWhenAnIntegrationHookThrowsAndCanBeRetried(string $throwingHook): void
    {
        $this->withRestoredHandlerState(function (Closure $track) use ($throwingHook): void {
            $previousHandler = static fn (): bool => true;
            set_error_handler($previousHandler, E_USER_NOTICE);
            $previousExceptionHandler = static function (\Throwable $throwable): void {
            };
            set_exception_handler($previousExceptionHandler);
            ini_set('display_errors', 'stderr');
            ini_set('display_startup_errors', '1');
            ini_set('zend.exception_ignore_args', '1');

            $switch = new class() {
                public bool $fail = true;

                public function shouldFail(): bool
                {
                    return $this->fail;
                }
            };
            $hook = static function () use ($switch, $throwingHook): bool {
                if ($switch->shouldFail()) {
                    throw new RuntimeException($throwingHook . ' failed');
                }

                return true;
            };
            $handler = $track(new ErrorHandlerLib(
                $throwingHook === 'includeTraceArguments'
                    ? $this->createIntegration(includeTraceArgumentsHook: $hook)
                    : $this->createIntegration(shouldDisplayErrors: $hook),
                new ErrorHandlerNoOpSkipDecider()
            ));

            try {
                $handler->register();
                self::fail('Expected the integration hook to throw.');
            } catch (RuntimeException $exception) {
                self::assertSame($throwingHook . ' failed', $exception->getMessage());
            }

            self::assertFalse($handler->isRegistered());
            self::assertSame($previousHandler, self::currentErrorHandler());
            self::assertSame($previousExceptionHandler, self::currentExceptionHandler());
            self::assertSame('stderr', (string) ini_get('display_errors'));
            self::assertSame('1', (string) ini_get('display_startup_errors'));
            self::assertSame('1', (string) ini_get('zend.exception_ignore_args'));
            self::assertPreviousHandlerKeptItsUserNoticeMask();

            $switch->fail = false;
            $handler->register();
            self::assertTrue($handler->isRegistered());
            self::assertNotSame($previousHandler, self::currentErrorHandler());

            $handler->unregister();
            self::assertSame($previousHandler, self::currentErrorHandler());
            self::assertSame($previousExceptionHandler, self::currentExceptionHandler());
            self::assertSame('stderr', (string) ini_get('display_errors'));
            self::assertSame('1', (string) ini_get('zend.exception_ignore_args'));
        });
    }

    public function testShutdownFatalErrorGoesToTheActiveNestedRegistration(): void
    {
        $output = $this->runFixture('nested-registration-fatal.php');

        self::assertStringContainsString('HANDLED-BY:inner', $output);
        self::assertStringNotContainsString('HANDLED-BY:outer', $output);
        self::assertStringContainsString('FixtureClassDeclaredTwice', $output);
    }

    public function testShutdownFatalErrorGoesToTheOuterRegistrationOnceTheInnerIsUnregistered(): void
    {
        $output = $this->runFixture('nested-registration-fatal.php', ['unregister-inner']);

        self::assertStringContainsString('HANDLED-BY:outer', $output);
        self::assertStringNotContainsString('HANDLED-BY:inner', $output);
    }

    public function testUnregisteredHandlerIsNotRetainedByTheLibrary(): void
    {
        $this->withRestoredHandlerState(function (): void {
            // Deliberately not tracked: the tracker would hold the reference this test needs gone.
            $handler = (new ErrorHandlerLib($this->createIntegration(), new ErrorHandlerNoOpSkipDecider()))->register();
            $handler->unregister();
            $reference = \WeakReference::create($handler);
            unset($handler);
            gc_collect_cycles();

            self::assertNull($reference->get(), 'A worker that creates one handler per job must not accumulate them.');
        });
    }

    public function testShutdownFatalErrorSurvivesAnEarlierHostShutdownCallbackThatRaisesASuppressedWarning(): void
    {
        $output = $this->runFixture('nested-registration-fatal.php', ['host-shutdown-overwrites-last-error']);

        self::assertStringContainsString('HANDLED-BY:inner', $output);
        self::assertStringContainsString('FixtureClassDeclaredTwice', $output);
    }

    /**
     * PHP 8.5 attaches the fatal error's backtrace to error_get_last(). Without it, the only trace
     * available at shutdown is the library's own, which never reaches the fixture's function.
     */
    #[\PHPUnit\Framework\Attributes\RequiresPhp('>= 8.5')]
    public function testShutdownFatalErrorUsesThePhp85FatalErrorBacktrace(): void
    {
        $output = $this->runFixture('nested-registration-fatal.php', ['fatal-inside-function']);

        self::assertStringContainsString('HANDLED-BY:inner', $output);
        self::assertMatchesRegularExpression('/^#\d+ .*includeRedeclaringFile\(\)$/m', $output);
    }

    /**
     * @return iterable<string, array{Closure(Throwable): void, int}>
     */
    public static function provideReportHelpers(): iterable
    {
        yield 'reportError()' => [static fn (Throwable $throwable) => ErrorHandlerLib::reportError($throwable), E_USER_WARNING];
        yield 'reportWarning()' => [static fn (Throwable $throwable) => ErrorHandlerLib::reportWarning($throwable), E_USER_DEPRECATED];
    }

    /**
     * @param Closure(Throwable): void $report
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideReportHelpers')]
    public function testReportHelpersDoNotLeakThrowableTraceArguments(Closure $report, int $expectedType): void
    {
        $previousIgnoreArgs = (string) ini_get('zend.exception_ignore_args');
        $previousMaxLength = (string) ini_get('zend.exception_string_param_max_len');
        ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '100');

        try {
            $throwable = self::createExceptionWithArgument('SECRET_42');
            $phpRendering = $throwable->__toString();
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
            ini_set('zend.exception_string_param_max_len', $previousMaxLength);
        }

        self::assertStringContainsString('SECRET_42', $phpRendering, 'Precondition: PHP itself would leak the argument.');

        // The emitted diagnostic itself.
        $emitted = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$emitted): bool {
            $emitted = [$errno, $errstr];

            return true;
        });

        try {
            $report($throwable);
        } finally {
            restore_error_handler();
        }

        self::assertIsArray($emitted, 'The helper must raise a diagnostic.');
        self::assertSame($expectedType, $emitted[0]);
        self::assertSame(
            'RuntimeException: ' . $throwable->getMessage() . ' in ' . $throwable->getFile() . ':' . $throwable->getLine(),
            $emitted[1]
        );

        // The same diagnostic through the library: log and rendered report.
        $rendered = [];
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                shouldEchoOutput: static fn (): bool => true,
                isTestingEnvironment: static fn (): bool => true,
                renderNonCriticalError: static function (string $label, string $details) use (&$rendered): void {
                    $rendered[] = $details;
                }
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        $log = $this->captureErrorLog(static function () use ($handler, $report, $throwable): void {
            set_error_handler($handler->handleError(...), E_ALL);

            try {
                $report($throwable);
            } finally {
                restore_error_handler();
            }
        }, E_ALL);

        self::assertCount(1, $rendered);
        self::assertStringContainsString('Login failed', $rendered[0]);
        self::assertStringNotContainsString('SECRET_42', $rendered[0]);
        self::assertStringContainsString('Login failed', $log);
        self::assertStringNotContainsString('SECRET_42', $log);
    }

    public function testPreviousExceptionChainOmitsArgumentValuesByDefault(): void
    {
        $previousIgnoreArgs = (string) ini_get('zend.exception_ignore_args');
        $previousMaxLength = (string) ini_get('zend.exception_string_param_max_len');
        ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '100');

        try {
            $previous = self::createExceptionWithArgument('SECRET_42');
            $throwable = new RuntimeException('current failure', 0, new RuntimeException('middle', 0, $previous));
            $phpRendering = $previous->__toString();
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
            ini_set('zend.exception_string_param_max_len', $previousMaxLength);
        }

        self::assertStringContainsString('SECRET_42', $phpRendering, 'Precondition: PHP itself would leak the argument.');

        $rendered = [];
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                renderCriticalError: static function (string $details) use (&$rendered): never {
                    $rendered[] = $details;

                    throw new RuntimeException('rendered');
                },
                shouldEchoOutput: static fn (): bool => true
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        $log = $this->captureErrorLog(static function () use ($handler, $throwable): void {
            try {
                $handler->handleException($throwable);
            } catch (RuntimeException) {
            }
        });

        self::assertCount(1, $rendered);
        self::assertStringContainsString('RuntimeException: middle', $rendered[0]);
        self::assertStringContainsString('createExceptionWithArgument', $log . $rendered[0]);
        self::assertStringNotContainsString('SECRET_42', $rendered[0], 'Neither the report nor the agent prompt may carry it.');
        self::assertStringNotContainsString('SECRET_42', $log);
    }

    public function testPreviousExceptionChainShowsArgumentValuesWhenOptedIn(): void
    {
        $previousIgnoreArgs = (string) ini_get('zend.exception_ignore_args');
        $previousMaxLength = (string) ini_get('zend.exception_string_param_max_len');
        ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '100');

        try {
            $throwable = new RuntimeException('current failure', 0, self::createExceptionWithArgument('visible-arg'));
        } finally {
            ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
            ini_set('zend.exception_string_param_max_len', $previousMaxLength);
        }

        $handler = new ErrorHandlerLib($this->createIntegration(includeTraceArguments: true), new ErrorHandlerNoOpSkipDecider());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('createExceptionWithArgument("visible-arg")');

        $handler->handleException($throwable);
    }

    private static function createExceptionWithArgument(string $password): RuntimeException
    {
        return new RuntimeException('Login failed for a ' . strlen($password) . '-character password');
    }

    /**
     * opcache_compile_file() catches a compile bailout and returns false; the compile error stays in
     * error_get_last() although the script goes on. It must not be replayed as a fatal at shutdown.
     */
    #[\PHPUnit\Framework\Attributes\RequiresPhpExtension('Zend OPcache')]
    public function testRecoveredCompileErrorIsNotReportedAsFatalAtShutdown(): void
    {
        $output = $this->runFixture('nested-registration-fatal.php', ['recovered-opcache-compile-error'], ['-d', 'opcache.enable_cli=1'], null);

        self::assertStringContainsString('OPCACHE-RECOVERED', $output, 'Precondition: the compile error was raised and recovered.');
        self::assertStringContainsString('SCRIPT-FINISHED', $output);
        self::assertStringNotContainsString('HANDLED-BY:', $output);
    }

    /**
     * PHP 8.5 clears the fatal-error backtrace as soon as the next diagnostic is raised, before any
     * error handler runs. The fatal itself is still reported, but without the frames that led to it.
     */
    #[\PHPUnit\Framework\Attributes\RequiresPhp('>= 8.5')]
    public function testFatalErrorBacktraceIsLostWhenAnEarlierShutdownCallbackRaisesADiagnostic(): void
    {
        $output = $this->runFixture('nested-registration-fatal.php', ['host-shutdown-overwrites-last-error']);

        self::assertStringContainsString('HANDLED-BY:inner', $output);
        self::assertStringContainsString('FixtureClassDeclaredTwice', $output);
        self::assertDoesNotMatchRegularExpression('/^#\d+ .*includeRedeclaringFile\(\)$/m', $output);
    }

    public function testRegisterCalledFromAnIntegrationHookInstallsOneHandlerPair(): void
    {
        $this->withRestoredHandlerState(function (Closure $track): void {
            $previousHandler = static fn (): bool => true;
            set_error_handler($previousHandler);

            $handler = null;
            $handler = $track(new ErrorHandlerLib(
                $this->createIntegration(includeTraceArgumentsHook: static function () use (&$handler): bool {
                    $handler?->register();

                    return false;
                }),
                new ErrorHandlerNoOpSkipDecider()
            ));
            $handler->register();
            self::assertTrue($handler->isRegistered());

            $handler->unregister();
            self::assertFalse($handler->isRegistered());
            self::assertSame($previousHandler, self::currentErrorHandler(), 'A second pair would still be on the stack.');
        });
    }

    /**
     * register() compares by PHP's own parsing rules: " on" is off for a boolean directive, and
     * display_errors=2 means stderr. Both must be changed, and restored verbatim.
     */
    public function testRegisterComparesIniValuesTheWayPhpParsesThem(): void
    {
        $this->withRestoredHandlerState(function (Closure $track): void {
            ini_set('display_startup_errors', ' on');

            $handler = $track(new ErrorHandlerLib(
                $this->createIntegration(shouldDisplayErrors: static fn (): bool => true),
                new ErrorHandlerNoOpSkipDecider()
            ))->register();
            self::assertSame('On', (string) ini_get('display_startup_errors'), '" on" is off for PHP, so it must be switched on.');
            $handler->unregister();
            self::assertSame(' on', (string) ini_get('display_startup_errors'));

            ini_set('display_errors', '2');
            $handler = $track(new ErrorHandlerLib($this->createIntegration(), new ErrorHandlerNoOpSkipDecider()))->register();
            self::assertSame('Off', (string) ini_get('display_errors'), 'display_errors=2 is stderr, not off.');
            $handler->unregister();
            self::assertSame('2', (string) ini_get('display_errors'));
        });
    }

    /**
     * An anonymous class name contains a NUL byte, and error_log() stops writing at the first NUL.
     * A trace frame or object of an anonymous class must not cut off the rest of the log entry.
     */
    public function testAnonymousClassNamesDoNotTruncateTheLogEntry(): void
    {
        $anonymous = new class() {
        };
        self::assertStringContainsString("\0", $anonymous::class, 'Precondition: PHP embeds a NUL byte.');

        $handler = new ErrorHandlerLib($this->createIntegration(includeTraceArguments: true), new ErrorHandlerNoOpSkipDecider());
        $trace = [['file' => '/app/src/Job.php', 'line' => 8, 'class' => $anonymous::class, 'type' => '->', 'function' => 'run', 'args' => [$anonymous]]];

        $log = $this->captureErrorLog(static function () use ($handler, $trace): void {
            $handler->handleError(E_USER_WARNING, 'anonymous frame', '/app/src/Job.php', 8, ['after' => 'context-survives'], $trace);
        }, E_ALL);

        self::assertStringContainsString('class@anonymous->run(Object(class@anonymous))', $log);
        self::assertStringContainsString('context-survives', $log, 'Everything after the anonymous name must reach the log.');
        self::assertStringNotContainsString("\0", $log);
    }

    /**
     * A diagnostic raised while a chained exception's report is being built (here from
     * getGlobalInfo()) must not carry the outer exception's causes.
     */
    public function testNestedDiagnosticDoesNotInheritThePreviousThrowableChain(): void
    {
        $handler = null;
        $nested = false;
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                getGlobalInfo: static function () use (&$handler, &$nested): string {
                    if (!$nested) {
                        $nested = true;
                        $handler?->handleError(E_USER_WARNING, 'nested from getGlobalInfo', '/app/src/Info.php', 4);
                    }

                    return '';
                },
                renderCriticalError: static function (string $details): never {
                    throw new RuntimeException($details);
                }
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        $rendered = '';
        $log = $this->captureErrorLog(static function () use ($handler, &$rendered): void {
            try {
                $handler->handleException(new RuntimeException('outer', 0, new RuntimeException('the cause')));
            } catch (RuntimeException $exception) {
                $rendered = $exception->getMessage();
            }
        }, E_ALL);

        [$nestedEntry] = explode('PHP-ERROR', $log, 2);
        self::assertStringContainsString('nested from getGlobalInfo', $nestedEntry);
        self::assertStringNotContainsString('Previous throwables', $nestedEntry);
        self::assertStringContainsString('RuntimeException: the cause', $rendered, 'The outer report keeps its own chain.');
    }

    // ---------------------------------------------------------------------------------------------
    // Public-interface contract tests. They pin observable behavior through the integration hooks,
    // error_log(), ini values and handleError()'s return value - never private methods.
    // ---------------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function provideDisplayDecisions(): iterable
    {
        yield 'shouldDisplayErrors() true, display_errors was 0' => [true, '0'];
        yield 'shouldDisplayErrors() true, display_errors was 1' => [true, '1'];
        yield 'shouldDisplayErrors() false, display_errors was 0' => [false, '0'];
        yield 'shouldDisplayErrors() false, display_errors was 1' => [false, '1'];
    }

    /**
     * `true` preserves display_errors (it does not force it on) and turns display_startup_errors on;
     * `false` turns both off. handleError() returns `false` (let PHP display it too) exactly when
     * display_errors is effectively on at the time of the diagnostic.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideDisplayDecisions')]
    public function testShouldDisplayErrorsDecidesTheIniStateAndTheReturnValue(bool $displayErrors, string $before): void
    {
        $this->withRestoredHandlerState(function (Closure $track) use ($displayErrors, $before): void {
            ini_set('display_errors', $before);
            ini_set('display_startup_errors', $displayErrors ? '0' : '1');

            $handler = $track(new ErrorHandlerLib(
                $this->createIntegration(shouldDisplayErrors: static fn (): bool => $displayErrors),
                new ErrorHandlerNoOpSkipDecider()
            ))->register();

            $expectDisplayOn = $displayErrors && $before === '1';
            self::assertSame($expectDisplayOn, filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN));
            self::assertSame($displayErrors, filter_var(ini_get('display_startup_errors'), FILTER_VALIDATE_BOOLEAN));

            $returned = null;
            $this->captureErrorLog(static function () use ($handler, &$returned): void {
                $returned = $handler->handleError(E_USER_WARNING, 'display decision', '/app/src/A.php', 3);
            }, E_ALL);
            self::assertSame(!$expectDisplayOn, $returned);

            $handler->unregister();
            self::assertSame($before, (string) ini_get('display_errors'));
            self::assertSame($displayErrors ? '0' : '1', (string) ini_get('display_startup_errors'));
        });
    }

    /**
     * @return iterable<string, array{?SuppressedDiagnosticPolicy, int, bool}>
     */
    public static function provideDiagnosticMatrix(): iterable
    {
        $policies = [
            'Observe' => SuppressedDiagnosticPolicy::Observe,
            'LogOnly' => SuppressedDiagnosticPolicy::LogOnly,
            'Ignore' => SuppressedDiagnosticPolicy::Ignore,
            'not suppressed' => null,
        ];
        $types = ['warning' => E_USER_WARNING, 'notice' => E_USER_NOTICE, 'deprecation' => E_USER_DEPRECATED];

        foreach ($policies as $policyName => $policy) {
            foreach ($types as $typeName => $type) {
                foreach (['testing' => true, 'non-testing' => false] as $environmentName => $testing) {
                    yield "{$policyName}, {$typeName}, {$environmentName}" => [$policy, $type, $testing];
                }
            }
        }
    }

    /**
     * $policy null means the diagnostic is not PHP-suppressed (controls; the policy is irrelevant).
     * Suppression uses an explicit mask that excludes exactly this type.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideDiagnosticMatrix')]
    public function testDiagnosticMatrixAcrossPolicyTypeAndEnvironment(?SuppressedDiagnosticPolicy $policy, int $errno, bool $testing): void
    {
        $rendered = [];
        $debugBar = [];
        $skipCalls = [];
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                shouldEchoOutput: static fn (): bool => true,
                isTestingEnvironment: static fn (): bool => $testing,
                isDebugBarRequest: static fn (): bool => true,
                addDebugBarMessage: static function (int $type, string $description) use (&$debugBar): void {
                    $debugBar[] = $type;
                },
                renderNonCriticalError: static function (string $label, string $details, bool $echoOutput) use (&$rendered): void {
                    $rendered[] = [$label, $echoOutput];
                },
                suppressedDiagnosticPolicy: $policy ?? SuppressedDiagnosticPolicy::Ignore
            ),
            self::skipDecider(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$skipCalls): bool {
                $skipCalls[] = [$errno, $errstr, $errfile, $errline];

                return false;
            })
        );

        $previousDisplayErrors = (string) ini_get('display_errors');
        ini_set('display_errors', '0');
        $returned = null;

        try {
            $log = $this->captureErrorLog(static function () use ($handler, $errno, &$returned): void {
                $returned = $handler->handleError($errno, 'matrix event', '/app/src/Matrix.php', 7);
            }, $policy === null ? E_ALL : E_ALL & ~$errno);
        } finally {
            ini_set('display_errors', $previousDisplayErrors);
        }

        $processed = $policy !== SuppressedDiagnosticPolicy::Ignore;
        $reportable = $processed && ($errno === E_USER_WARNING || $testing);
        $renderable = $reportable && $policy !== SuppressedDiagnosticPolicy::LogOnly;

        self::assertSame($policy === null, $returned, 'Suppressed: always false. Reported, display_errors off: true.');
        self::assertSame($processed ? [[$errno, 'matrix event', '/app/src/Matrix.php', 7]] : [], $skipCalls);
        self::assertSame($processed ? [$errno] : [], $debugBar, 'The debug bar records every processed diagnostic.');
        self::assertSame($reportable, str_contains($log, 'matrix event'), 'Notices and deprecations are logged in testing only.');
        self::assertSame($renderable ? [[$errno === E_USER_WARNING ? 'Warning' : 'Notice', true]] : [], $rendered);
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function provideEchoDecisions(): iterable
    {
        yield 'echo on, display_errors off' => [true, '0'];
        yield 'echo off, display_errors on' => [false, '1'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideEchoDecisions')]
    public function testShouldEchoOutputDecidesRenderingIndependentlyOfDisplayErrors(bool $echo, string $displayErrors): void
    {
        $rendered = [];
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                shouldEchoOutput: static fn (): bool => $echo,
                renderNonCriticalError: static function (string $label, string $details, bool $echoOutput) use (&$rendered): void {
                    $rendered[] = [$details, $echoOutput];
                }
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        $previousDisplayErrors = (string) ini_get('display_errors');
        ini_set('display_errors', $displayErrors);

        try {
            $log = $this->captureErrorLog(static function () use ($handler): void {
                $handler->handleError(E_USER_WARNING, 'echo decision', '/app/src/Echo.php', 5);
            }, E_ALL);
        } finally {
            ini_set('display_errors', $previousDisplayErrors);
        }

        self::assertStringContainsString('echo decision', $log, 'Logging never depends on echo.');
        self::assertStringNotContainsString('COPY EVERYTHING BELOW INTO A CODING AGENT', $log);

        if (!$echo) {
            self::assertSame([], $rendered);

            return;
        }

        self::assertCount(1, $rendered);
        self::assertTrue($rendered[0][1]);
        self::assertStringContainsString('COPY EVERYTHING BELOW INTO A CODING AGENT', $rendered[0][0]);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideEchoFlags(): iterable
    {
        yield 'echo on' => [true];
        yield 'echo off' => [false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideEchoFlags')]
    public function testCriticalRendererReceivesTheEchoFlagAndThePromptOnlyWhenEchoing(bool $echo): void
    {
        $received = [];
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                renderCriticalError: static function (string $details, bool $echoOutput) use (&$received): never {
                    $received[] = [$details, $echoOutput];

                    throw new RuntimeException('rendered');
                },
                shouldEchoOutput: static fn (): bool => $echo
            ),
            new ErrorHandlerNoOpSkipDecider()
        );

        $log = $this->captureErrorLog(static function () use ($handler): void {
            try {
                $handler->handleError(E_USER_ERROR, 'critical echo', '/app/src/Crit.php', 9);
            } catch (RuntimeException) {
            }
        }, E_ALL);

        self::assertCount(1, $received);
        self::assertSame($echo, $received[0][1]);
        self::assertSame($echo, str_contains($received[0][0], 'COPY EVERYTHING BELOW INTO A CODING AGENT'));
        self::assertStringContainsString('critical echo', $log);
        self::assertStringNotContainsString('COPY EVERYTHING BELOW INTO A CODING AGENT', $log);
    }

    /**
     * useIntegration() sets the process defaults: static helpers and argument-less instances use
     * them. A constructor argument overrides the integration for that instance only; an instance
     * built without a skip decider still takes the default one.
     */
    public function testUseIntegrationDefaultsAndConstructorOverride(): void
    {
        $defaultLog = [];
        $overrideLog = [];
        $skipCalls = [];
        $decider = self::skipDecider(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$skipCalls): bool {
            $skipCalls[] = [$errno, $errstr, $errfile, $errline];

            return $errstr === 'skip me';
        });

        ErrorHandlerLib::useIntegration(
            $this->createIntegration(
                describeObject: static fn (object $value): string => 'DefaultDescribed',
                renderJavaScriptErrorHandler: static fn (): string => '<script>default</script>',
                shouldEchoOutput: static fn (): bool => true,
                renderNonCriticalError: static function (string $label, string $details) use (&$defaultLog): void {
                    $defaultLog[] = $details;
                }
            ),
            $decider
        );

        self::assertSame('DefaultDescribed', ErrorHandlerLib::getTypeInfo(new stdClass(), false));
        self::assertSame('<script>default</script>', ErrorHandlerLib::getGlobalJavaScriptErrorHandler());

        $defaultHandler = new ErrorHandlerLib();
        $overrideHandler = new ErrorHandlerLib($this->createIntegration(
            shouldEchoOutput: static fn (): bool => true,
            renderNonCriticalError: static function (string $label, string $details) use (&$overrideLog): void {
                $overrideLog[] = $details;
            }
        ));

        $this->captureErrorLog(static function () use ($defaultHandler, $overrideHandler): void {
            $defaultHandler->handleError(E_USER_WARNING, 'skip me', '/app/src/Skip.php', 10);
            $defaultHandler->handleError(E_USER_WARNING, 'keep me', '/app/src/Skip.php', 11);
            $overrideHandler->handleError(E_USER_WARNING, 'override keeps me', '/app/src/Other.php', 12);
        }, E_ALL);

        self::assertSame(
            [
                [E_USER_WARNING, 'skip me', '/app/src/Skip.php', 10],
                [E_USER_WARNING, 'keep me', '/app/src/Skip.php', 11],
                [E_USER_WARNING, 'override keeps me', '/app/src/Other.php', 12],
            ],
            $skipCalls,
            'The decider gets the exact diagnostic; the override instance still uses the default decider.'
        );
        self::assertCount(1, $defaultLog);
        self::assertStringContainsString('keep me', $defaultLog[0]);
        self::assertCount(1, $overrideLog);
        self::assertStringContainsString('override keeps me', $overrideLog[0]);

        self::assertSame('DefaultDescribed', ErrorHandlerLib::getTypeInfo(new stdClass(), false), 'The override does not leak into statics.');
    }

    /**
     * getGlobalInfo(), describeObject() and sanitizeErrorDetails() compose on every channel. The
     * secret is planted in the message, the context, a trace argument, the object description and
     * the global info; only the sanitized form may leave the library.
     */
    public function testIntegrationHooksComposeAcrossLogRendererDebugBarAndPrompt(): void
    {
        $rendered = [];
        $debugBar = [];
        $handler = new ErrorHandlerLib(
            $this->createIntegration(
                describeObject: static fn (object $value): string => 'Described(token=hunter2)',
                getGlobalInfo: static fn (): string => "GLOBAL-INFO request=hunter2\n",
                sanitizeErrorDetails: static fn (string $details): string => str_replace('hunter2', '[REDACTED]', $details),
                shouldEchoOutput: static fn (): bool => true,
                isDebugBarRequest: static fn (): bool => true,
                addDebugBarMessage: static function (int $errno, string $description) use (&$debugBar): void {
                    $debugBar[] = $description;
                },
                renderNonCriticalError: static function (string $label, string $details) use (&$rendered): void {
                    $rendered[] = $details;
                },
                includeTraceArguments: true
            ),
            new ErrorHandlerNoOpSkipDecider()
        );
        $trace = [['file' => '/app/src/Auth.php', 'line' => 21, 'class' => 'App\\Auth', 'type' => '->', 'function' => 'login', 'args' => [new stdClass(), 'hunter2']]];

        $log = $this->captureErrorLog(static function () use ($handler, $trace): void {
            $handler->handleError(E_USER_WARNING, 'login hunter2 failed', '/app/src/Auth.php', 21, ['token' => 'hunter2'], $trace);
        }, E_ALL);

        self::assertCount(1, $rendered);
        self::assertCount(1, $debugBar);
        [$report, $prompt] = explode('----- COPY EVERYTHING BELOW INTO A CODING AGENT -----', $rendered[0], 2);

        foreach (['log' => $log, 'report' => $report] as $channel => $output) {
            self::assertStringContainsString('GLOBAL-INFO request=[REDACTED]', $output, $channel);
            self::assertStringContainsString('App\\Auth->login(Described(token=[REDACTED]), "[REDACTED]")', $output, $channel);
            self::assertStringContainsString("[token] => \"[REDACTED]\"", $output, $channel);
        }

        self::assertStringContainsString('App\\Auth->login(Described(token=[REDACTED]), "[REDACTED]")', $debugBar[0]);
        self::assertStringNotContainsString('GLOBAL-INFO', $debugBar[0], 'The debug bar carries no global info.');

        self::assertStringContainsString('App\\Auth->login()', $prompt, 'The prompt never renders arguments, even when opted in.');
        self::assertStringNotContainsString('Described(', $prompt);
        self::assertStringContainsString('token: "[REDACTED]"', $prompt);
        self::assertStringNotContainsString('GLOBAL-INFO', $prompt);

        foreach (['log' => $log, 'rendered' => $rendered[0], 'debug bar' => $debugBar[0]] as $channel => $output) {
            self::assertStringNotContainsString('hunter2', $output, $channel);
        }
    }

    /**
     * Rendering is capped at two warnings and two notices per instance; logging is not capped, and
     * each instance has its own counters.
     */
    public function testRenderCapsArePerInstanceAndNeverStopLogging(): void
    {
        $renderedA = [];
        $renderedB = [];
        $makeHandler = function (array &$rendered): ErrorHandlerLib {
            return new ErrorHandlerLib(
                $this->createIntegration(
                    shouldEchoOutput: static fn (): bool => true,
                    isTestingEnvironment: static fn (): bool => true,
                    renderNonCriticalError: static function (string $label) use (&$rendered): void {
                        $rendered[] = $label;
                    }
                ),
                new ErrorHandlerNoOpSkipDecider()
            );
        };
        $handlerA = $makeHandler($renderedA);
        $handlerB = $makeHandler($renderedB);

        $log = $this->captureErrorLog(static function () use ($handlerA, $handlerB): void {
            for ($i = 1; $i <= 4; $i++) {
                $handlerA->handleError(E_USER_WARNING, "capped warning {$i}", '/app/src/Cap.php', $i);
                $handlerA->handleError(E_USER_NOTICE, "capped notice {$i}", '/app/src/Cap.php', $i);
            }

            $handlerB->handleError(E_USER_WARNING, 'fresh warning', '/app/src/Cap.php', 9);
        }, E_ALL);

        self::assertSame(['Warning', 'Notice', 'Warning', 'Notice'], $renderedA);
        self::assertSame(['Warning'], $renderedB, 'Another instance starts with its own counters.');

        for ($i = 1; $i <= 4; $i++) {
            self::assertStringContainsString("capped warning {$i}", $log);
            self::assertStringContainsString("capped notice {$i}", $log);
        }
    }

    /**
     * @param Closure(int, string, string, int): bool $decide
     */
    private static function skipDecider(Closure $decide): ErrorHandlerSkipDeciderInterface
    {
        return new class($decide) implements ErrorHandlerSkipDeciderInterface {
            public function __construct(private readonly Closure $decide)
            {
            }

            public function shouldSkip(int $errno, string $errstr, string $errfile, int $errline): bool
            {
                return (bool) ($this->decide)($errno, $errstr, $errfile, $errline);
            }
        };
    }

    /**
     * Runs $callback and then restores the error handler stack, the exception handler stack and the
     * ini directives register() may touch, whether or not an assertion inside $callback failed.
     *
     * $callback receives a tracker: pass every ErrorHandlerLib instance through it. Cleanup first
     * unregisters those (newest first) while their handlers are still on top, so no test instance
     * stays eligible for the shutdown dispatcher. Sentinel handlers then mark the original top of
     * each stack, so cleanup pops exactly what the test left behind.
     *
     * @param Closure(Closure(ErrorHandlerLib): ErrorHandlerLib): void $callback
     */
    private function withRestoredHandlerState(Closure $callback): void
    {
        $tracked = [];
        $track = static function (ErrorHandlerLib $handler) use (&$tracked): ErrorHandlerLib {
            $tracked[] = $handler;

            return $handler;
        };

        $directives = ['display_errors', 'display_startup_errors', 'zend.exception_ignore_args'];
        $iniValues = [];
        foreach ($directives as $directive) {
            $iniValues[$directive] = (string) ini_get($directive);
        }

        $errorSentinel = static fn (): bool => false;
        $exceptionSentinel = static function (\Throwable $throwable): void {
        };
        set_error_handler($errorSentinel);
        set_exception_handler($exceptionSentinel);

        try {
            $callback($track);
        } finally {
            foreach (array_reverse($tracked) as $handler) {
                try {
                    $handler->unregister();
                } catch (\LogicException) {
                    // Something the test installed sits above it; popped below.
                }
            }

            for ($i = 0; $i < 20 && self::currentErrorHandler() !== $errorSentinel; $i++) {
                restore_error_handler();
            }
            restore_error_handler();

            for ($i = 0; $i < 20 && self::currentExceptionHandler() !== $exceptionSentinel; $i++) {
                restore_exception_handler();
            }
            restore_exception_handler();

            foreach ($iniValues as $directive => $value) {
                ini_set($directive, $value);
            }
        }
    }

    /**
     * Reads the active error handler without changing the stack (the probe is popped again, and the
     * entry below it keeps its original type mask).
     */
    private static function currentErrorHandler(): mixed
    {
        $current = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $current;
    }

    private static function currentExceptionHandler(): mixed
    {
        $current = set_exception_handler(static function (\Throwable $throwable): void {
        });
        restore_exception_handler();

        return $current;
    }

    /**
     * The active handler was installed for E_USER_NOTICE only: a user warning must bypass it and
     * reach PHP, which records it. A handler re-installed for E_ALL would swallow it instead.
     */
    private static function assertPreviousHandlerKeptItsUserNoticeMask(): void
    {
        $previousLevel = error_reporting(E_ALL);
        $previousLogErrors = (string) ini_get('log_errors');
        $previousDisplayErrors = (string) ini_get('display_errors');
        ini_set('log_errors', '0');
        ini_set('display_errors', '0');

        try {
            error_clear_last();
            @trigger_error('outside the mask', E_USER_WARNING);
            $lastError = error_get_last();
        } finally {
            error_reporting($previousLevel);
            ini_set('log_errors', $previousLogErrors);
            ini_set('display_errors', $previousDisplayErrors);
        }

        self::assertSame('outside the mask', $lastError['message'] ?? null, 'The previous handler lost its type mask.');
    }

    /**
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    private function withErrorReporting(int $level, Closure $callback): mixed
    {
        $previous = error_reporting($level);

        try {
            return $callback();
        } finally {
            error_reporting($previous);
        }
    }

    /**
     * Runs a fixture script in its own PHP process and returns its combined output.
     *
     * The fatal and shutdown paths cannot be exercised in-process, so they are proven end to end.
     */
    /**
     * @param list<string> $arguments
     * @param list<string> $phpOptions e.g. ['-d', 'opcache.enable_cli=1']
     * @param null|int     $expectedExitCode null: the exit code is PHP's business, not checked
     */
    private function runFixture(string $fixtureName, array $arguments = [], array $phpOptions = [], ?int $expectedExitCode = 1): string
    {
        $fixture = __DIR__ . '/Fixture/' . $fixtureName;
        self::assertFileExists($fixture);

        $process = proc_open(
            [PHP_BINARY, ...$phpOptions, $fixture, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($expectedExitCode !== null) {
            self::assertSame($expectedExitCode, $exitCode, 'The fixture must exit through renderCriticalError().');
        }

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
        SuppressedDiagnosticPolicy $suppressedDiagnosticPolicy = SuppressedDiagnosticPolicy::Observe,
        bool $includeTraceArguments = false,
        ?Closure $includeTraceArgumentsHook = null,
        ?Closure $shouldDisplayErrors = null
    ): ErrorHandlerIntegrationInterface {
        return new class($describeObject, $renderJavaScriptErrorHandler, $getGlobalInfo, $sanitizeErrorDetails, $renderCriticalError, $shouldEchoOutput, $isTestingEnvironment, $isDebugBarRequest, $addDebugBarMessage, $renderNonCriticalError, $suppressedDiagnosticPolicy, $includeTraceArguments, $includeTraceArgumentsHook, $shouldDisplayErrors) implements ErrorHandlerIntegrationInterface {
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
                private readonly SuppressedDiagnosticPolicy $suppressedDiagnosticPolicy,
                private readonly bool $includeTraceArguments,
                private readonly ?Closure $includeTraceArgumentsHook,
                private readonly ?Closure $shouldDisplayErrors
            ) {
            }

            public function includeTraceArguments(): bool
            {
                return $this->includeTraceArgumentsHook ? (bool) ($this->includeTraceArgumentsHook)() : $this->includeTraceArguments;
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
                return $this->shouldDisplayErrors ? (bool) ($this->shouldDisplayErrors)() : false;
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
