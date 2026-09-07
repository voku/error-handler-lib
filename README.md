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
| `LogOnly` | logged and recorded, never rendered into the output | production, or a narrow policy for an external tool run |
| `Ignore` | not processed at all; PHP's normal suppression semantics apply | production that wants PHP defaults back |

Note that PHPUnit narrows `error_reporting()` to a fatal-only mask while a test runs, so under
PHPUnit almost every diagnostic looks PHP-suppressed. `Observe` is what keeps the handler useful
there.

### What `SkipDecider` guarantees

A skipped diagnostic produces **no observable processing side effects**: no logging, no debug-bar
entry, no rendering, no counters, no further integration callbacks. `handleError()` returns `true`
for it, because the host explicitly claimed the diagnostic.

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
