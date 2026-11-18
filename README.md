# ErrorHandlerLib

A small, portable PHP error-handler library with application integration hooks for logging, rendering, filtering, and object descriptions.

The package is designed to be installed through Composer and then adapted by the host application through `ErrorHandlerIntegrationInterface`.

## Installation

```bash
composer require voku/error-handler-lib
```

## Usage

```php
<?php

declare(strict_types=1);

use voku\ErrorHandlerLib\ErrorHandlerLib;

(new ErrorHandlerLib())->register();

ErrorHandlerLib::reportWarning('Deprecated path used.');
ErrorHandlerLib::reportError('Unexpected state detected.');
```

For application-specific behavior, provide an integration:

```php
<?php

declare(strict_types=1);

use voku\ErrorHandlerLib\ErrorHandlerLib;
use voku\ErrorHandlerLib\ErrorHandlerIntegrationInterface;

final class ApplicationErrorIntegration implements ErrorHandlerIntegrationInterface
{
    // Implement logging, sanitizing, rendering, and environment decisions here.
}

ErrorHandlerLib::useIntegration(new ApplicationErrorIntegration());
(new ErrorHandlerLib())->register();
```

`useIntegration()` sets the process-wide default. Passing an integration to the constructor configures
that one instance only and leaves the defaults alone.

### Registering and unregistering

`register()` is idempotent per instance. `unregister()` reverts it: the previous error handler comes
back with its original type mask, the previous exception handler comes back, every ini value
`register()` changed is restored, and the shutdown callback becomes a no-op. Handler stacks are LIFO,
so if something registered a handler after this one, `unregister()` throws a `LogicException` and
changes nothing.

With nested registrations, a fatal error at shutdown goes to the most recently registered instance,
the same one whose error handler is active. After `unregister()` the library holds no reference
to the instance, so a worker can create one handler per job.

```php
$handler = (new ErrorHandlerLib())->register();
// ... run a worker job, a test, an embedded tool ...
$handler->unregister();
```

### Stack trace arguments are opt-in

Argument values are the part of a stack trace most likely to carry passwords, tokens and personal
data, so traces render `App\Auth->login()` rather than `App\Auth->login("admin", "hunter2")` by
default. An integration opts in with `includeTraceArguments(): bool`. Opting in also makes
`register()` set `zend.exception_ignore_args=Off`. Without it, the library leaves that setting alone.

`sanitizeErrorDetails()` runs on every channel the library writes to: `error_log()`, rendered
reports, the coding-agent prompt and debug-bar messages.

## The error report hands you a prompt

Every rendered error report ends with a copy&paste-ready prompt for a coding agent, built from the
diagnostic the handler already has:

```text
----- COPY EVERYTHING BELOW INTO A CODING AGENT -----

A PHP diagnostic was captured by voku/error-handler-lib. Facts first:

  type:       E_WARNING (2)
  message:    file_get_contents(/etc/app/config.json): Failed to open stream: No such file or directory
  origin:     src/Config/Loader.php:42
  visibility: PHP had excluded this diagnostic from error_reporting() (an `@` or an explicit mask),
              so it would normally have been invisible. You are seeing it because the host
              policy observes suppressed diagnostics.
  runtime:    PHP 8.4.19 (cli)

Call path (innermost first, arguments omitted on purpose):

  1. src/Config/Loader.php:42 App\Config\Loader->read()
  2. src/Kernel.php:88 App\Kernel->boot()
  ...
```

It is deterministic: no timestamps, no request identifiers, no environment probing, no model call.
The same diagnostic always renders the same bytes, so repeated occurrences deduplicate.

The `visibility` line is the part worth having. An agent that is told a warning was hidden by `@`
does not "fix" it by hiding it again, and the prompt says so explicitly.

Two boundaries it respects:

- **Call arguments are never rendered.** They are the values most likely to hold credentials, so the
  call path carries navigation only. The whole prompt still passes through
  `sanitizeErrorDetails()` exactly like the error details do.
- **Logs stay machine-shaped.** The prompt is appended to what a human is about to read, not to
  `error_log()`, and only when the integration echoes output.

Call `debugPrompt()` directly to put the same text somewhere else — a debug-bar panel, an issue
template, a chat message:

```php
$prompt = $handler->debugPrompt($errno, $errstr, $errfile, $errline, $context, $backtrace);
```

## PHP suppression vs. application filtering

The handler is registered for `E_ALL` and does **not** simply obey `error_reporting()`. That is
deliberate: a warning hidden by `@` is often the most valuable thing on the screen.

```php
// Fails silently under PHP's own rules. The handler still shows you why.
$config = @file_get_contents($path);
```

Please do not write new `@` code because of this. The point is that existing `@` in your own code,
in vendor code, or in a tool you are running does not quietly cost you an afternoon of debugging.

Three separate decisions are evaluated in this order, and only the first one is PHP's:

| # | Question | Owner |
| - | -------- | ----- |
| 1 | Is this diagnostic critical/fatal? | the library — critical diagnostics skip steps 2 and 3 entirely |
| 2 | PHP excluded this diagnostic; is it still interesting? | `ErrorHandlerIntegrationInterface::suppressedDiagnosticPolicy()` |
| 3 | Is this specific diagnostic intentionally ignorable? | `ErrorHandlerSkipDeciderInterface::shouldSkip()` |

Only after all three does anything observable happen (debug bar, logging, rendering).

### Choosing a suppressed-diagnostic policy

"Suppressed" means the `error_reporting()` state active while the diagnostic was raised does not
contain its type — both `@expr` and an explicit `error_reporting()` mask.

```php
use voku\ErrorHandlerLib\SuppressedDiagnosticPolicy;

public function suppressedDiagnosticPolicy(): SuppressedDiagnosticPolicy
{
    return SuppressedDiagnosticPolicy::Observe;
}
```

| Case | Behavior | Typical host |
| ---- | -------- | ------------ |
| `Observe` (default) | suppressed diagnostics are handled like reported ones | development, tests, CI |
| `LogOnly` | handled like a reported diagnostic of the same type, but never rendered into the output | production, or a narrow policy for an external tool run |
| `Ignore` | not processed at all; PHP's normal suppression semantics apply | production that wants PHP defaults back |

The policy decides *whether* a suppressed diagnostic is processed, not *how*: notices and
deprecations are only logged or rendered when `isTestingEnvironment()` is true, whatever the policy.

Whatever the policy, the handler returns `false` for a PHP-suppressed diagnostic. PHP's native
handler then still respects the `@` (nothing is displayed or logged), but records the diagnostic,
so `error_get_last()` keeps working for code such as
`if (@fopen($path, 'rb') === false) { $error = error_get_last(); }`.

Note that PHPUnit narrows `error_reporting()` to a fatal-only mask while a test runs, so under
PHPUnit almost every diagnostic looks PHP-suppressed. `Observe` is what keeps the handler useful
there.

### What `SkipDecider` guarantees

A skipped diagnostic produces **no observable processing side effects**: no logging, no debug-bar
entry, no rendering, no counters, no further integration callbacks. `handleError()` returns `true`
for it, because the host explicitly claimed the diagnostic. The only exception is a PHP-suppressed
diagnostic, which returns `false` so that `error_get_last()` keeps working (see above).

`Ignore` returns `false` instead: the library declines and leaves the diagnostic to PHP.

### Guarantees that no policy can weaken

Critical diagnostics, uncaught exceptions, and shutdown-detected fatal errors are handled before
the suppression policy and the skip decider are consulted. Neither can hide them, whatever
`error_reporting()` says. `tests/Fixture/` proves this end to end against the most hostile host
policy the API allows.

## Development

```bash
composer install
composer test
composer phpstan
composer ci
```
