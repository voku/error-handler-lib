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
        ?Closure $renderNonCriticalError = null
    ): ErrorHandlerIntegrationInterface {
        return new class($describeObject, $renderJavaScriptErrorHandler, $getGlobalInfo, $sanitizeErrorDetails, $renderCriticalError, $shouldEchoOutput, $isTestingEnvironment, $isDebugBarRequest, $addDebugBarMessage, $renderNonCriticalError) implements ErrorHandlerIntegrationInterface {
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
                private readonly ?Closure $renderNonCriticalError
            ) {
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
