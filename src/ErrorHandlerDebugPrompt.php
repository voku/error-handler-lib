<?php

declare(strict_types=1);

namespace voku\ErrorHandlerLib;

/**
 * Renders one diagnostic as a copy&paste-ready prompt for a coding agent.
 *
 * The point is the handover, not the prose. A developer who is already looking at an error report
 * should not have to retype the message, hunt for the call path, or explain to an agent that the
 * warning was invisible to PHP - all of that is known here and is worth exactly one paste.
 *
 * The output is a pure function of the diagnostic: the same error always renders the same prompt,
 * with no timestamps, no request identifiers and no environment probing. Two occurrences of the
 * same bug therefore produce byte-identical prompts and can be deduplicated.
 *
 * @phpstan-import-type TraceList from ErrorHandlerLib
 */
final class ErrorHandlerDebugPrompt
{
    private const int MAX_TRACE_FRAMES = 12;

    /**
     * @param array<string, scalar|null> $context
     * @param null|TraceList             $backtrace
     */
    public static function build(
        int $errno,
        string $errstr,
        string $errfile,
        int $errline,
        bool $suppressedByPhp,
        array $context = [],
        ?array $backtrace = null
    ): string {
        $sections = [
            self::header(),
            self::diagnostic($errno, $errstr, $errfile, $errline, $suppressedByPhp),
            self::callPath($backtrace),
            self::context($context),
            self::task($errfile, $errline, $suppressedByPhp),
            self::footer(),
        ];

        return "\n" . implode("\n", array_filter($sections, static fn (string $section): bool => $section !== ''));
    }

    private static function header(): string
    {
        return "----- COPY EVERYTHING BELOW INTO A CODING AGENT -----\n";
    }

    private static function footer(): string
    {
        return "----- END -----\n";
    }

    private static function diagnostic(
        int $errno,
        string $errstr,
        string $errfile,
        int $errline,
        bool $suppressedByPhp
    ): string {
        $visibility = $suppressedByPhp
            ? 'PHP had excluded this diagnostic from error_reporting() (an `@` or an explicit mask),'
                . "\n              so it would normally have been invisible. You are seeing it because the host"
                . "\n              policy observes suppressed diagnostics."
            : 'reported normally by PHP.';

        return "A PHP diagnostic was captured by voku/error-handler-lib. Facts first:\n\n"
            . '  type:       ' . self::errorTypeName($errno) . ' (' . $errno . ")\n"
            . '  message:    ' . self::singleLine($errstr) . "\n"
            . '  origin:     ' . self::location($errfile, $errline) . "\n"
            . '  visibility: ' . $visibility . "\n"
            . '  runtime:    PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ")\n";
    }

    /**
     * @param null|TraceList $backtrace
     */
    private static function callPath(?array $backtrace): string
    {
        if ($backtrace === null || $backtrace === []) {
            return '';
        }

        $lines = '';
        $frameNumber = 0;

        foreach ($backtrace as $frame) {
            // The handler's own frames are noise: the developer wants their call path, not ours.
            if (str_starts_with(self::frameString($frame, 'class'), __NAMESPACE__ . '\\')) {
                continue;
            }

            if ($frameNumber >= self::MAX_TRACE_FRAMES) {
                $lines .= "  ... (deeper frames omitted)\n";

                break;
            }

            // Arguments are deliberately omitted: the call path is for navigation, and argument
            // values are the part most likely to carry credentials or personal data.
            $lines .= '  ' . ($frameNumber + 1) . '. '
                . self::location(self::frameString($frame, 'file'), self::frameInt($frame, 'line'))
                . ' ' . self::callee($frame) . "\n";
            $frameNumber++;
        }

        return "\nCall path (innermost first, arguments omitted on purpose):\n\n" . $lines;
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private static function context(array $context): string
    {
        if ($context === []) {
            return '';
        }

        $lines = '';
        foreach ($context as $name => $value) {
            $lines .= '  ' . $name . ': ' . ErrorHandlerLib::getTypeInfo($value, false) . "\n";
        }

        return "\nContext supplied by the application:\n\n" . $lines;
    }

    private static function task(string $errfile, int $errline, bool $suppressedByPhp): string
    {
        $silencingRule = $suppressedByPhp
            ? "Do not restore the suppression. This diagnostic was already hidden once; hiding it\n"
                . "   again is how it cost someone an afternoon in the first place.\n"
            : "Do not silence it with `@`, with error_reporting(), or with a skip rule. Those hide the\n"
                . "   symptom and keep the defect.\n";

        return "\nTask:\n\n"
            . '1. Reproduce it first. Add a failing automated test that provokes this diagnostic at '
            . self::location($errfile, $errline) . ",\n"
            . "   and only then change production code.\n"
            . "2. Find the root cause at the origin above, using the call path to reach it. State whether the\n"
            . "   defect is in this project's code, in a dependency, or in how this project calls one.\n"
            . '3. ' . $silencingRule
            . "4. Verify with this repository's own commands. Resolve them from composer.json scripts, the\n"
            . "   Makefile, or the CI workflow. Do not invent a command; if you cannot resolve one, say so.\n"
            . "5. Report what you changed, the exact commands you ran, their results, and anything you could\n"
            . "   not verify. Do not claim success beyond what you observed.\n";
    }

    private static function errorTypeName(int $errno): string
    {
        return match ($errno) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'UNKNOWN_ERROR_TYPE',
        };
    }

    private static function location(string $file, int $line): string
    {
        if ($file === '') {
            return '[internal function]';
        }

        return $line > 0 ? $file . ':' . $line : $file;
    }

    /**
     * @param array<string, mixed> $frame
     */
    private static function callee(array $frame): string
    {
        $callee = self::frameString($frame, 'class')
            . self::frameString($frame, 'type')
            . self::frameString($frame, 'function');

        return $callee === '' ? '[unknown]' : $callee . '()';
    }

    /**
     * @param array<string, mixed> $frame
     */
    private static function frameString(array $frame, string $key): string
    {
        $value = $frame[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $frame
     */
    private static function frameInt(array $frame, string $key): int
    {
        $value = $frame[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function singleLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
