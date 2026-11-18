# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

### Breaking changes

- `ErrorHandlerIntegrationInterface` now requires `includeTraceArguments(): bool`.
  Stack traces no longer render argument values unless it returns `true`,
  and `register()` only sets `zend.exception_ignore_args=Off` in that case.
  Return `true` to keep the previous output.
- `new ErrorHandlerLib($integration, $skipDecider)` no longer changes the
  process-wide defaults. Call `ErrorHandlerLib::useIntegration()` explicitly
  if static helpers such as `getTypeInfo()` or `getGlobalJavaScriptErrorHandler()`
  should use that integration.
- The previous-exception chain is no longer a `previous_error` context entry
  (which was truncated to 256 characters). It is a separate
  `Previous throwables (outermost first):` section in the report, formatted
  by the library so it honours `includeTraceArguments()`.
- `reportError()` and `reportWarning()` report a `Throwable` as class, message
  and origin instead of `Throwable::__toString()`, so the raised diagnostic no
  longer contains the throwable's own stack trace.

### Added

- `unregister()` restores the previous error handler (with its type mask), the
  previous exception handler, and every ini value `register()` changed.
  `isRegistered()` reports the state.
- On PHP 8.5+, a fatal error's backtrace from `error_get_last()` is used when
  PHP provides one.

### Fixed

- PHP-suppressed non-critical diagnostics now return `false` from `handleError()`
  under every policy and skip decision, so `error_get_last()` still sees them.
  Critical diagnostics still go to the non-returning critical renderer.
- A fatal error is still reported when a shutdown function registered before
  the library raises a diagnostic that would replace it in `error_get_last()`.
- If an integration hook throws inside `register()`, no handler or ini value
  has been changed yet, and `register()` can be retried.
- `unregister()` restores only ini values that `register()` actually changed;
  a directive that already had the target value is not overwritten later.
- Debug-bar messages now pass through `sanitizeErrorDetails()`.
- Calling `register()` twice no longer stacks a second error handler,
  exception handler or shutdown callback.
- Chained exceptions no longer leak argument values through
  `Throwable::__toString()` when `includeTraceArguments()` is `false`.
- With nested registrations, a shutdown-time fatal error now goes to the most
  recently registered (active) instance instead of the first one.
- A single static shutdown dispatcher replaces per-instance shutdown callbacks,
  so an unregistered handler is no longer retained until process exit.

### Documentation

- `LogOnly` follows the same notice/deprecation rules as reported diagnostics
  (logged only in a testing environment); this is now documented.
- `reportWarning()` deliberately raises `E_USER_DEPRECATED`; this is now documented.

## [0.1.0] - 2026-10-15

### Breaking changes

- `ErrorHandlerIntegrationInterface` now requires
  `suppressedDiagnosticPolicy(): SuppressedDiagnosticPolicy`. Existing custom
  integrations should return `SuppressedDiagnosticPolicy::Observe` to retain
  the previous handling of PHP-suppressed diagnostics.

### Added

- Hosts can select `Observe`, `LogOnly`, or `Ignore` for diagnostics PHP
  suppressed with `@` or an `error_reporting()` mask.
- Rendered reports can include a sanitized, copyable coding-agent prompt. Its
  call path omits argument values, and it is never sent to `error_log()`.

### Changed

- A skipped diagnostic produces no debug-bar entry, logging, rendering,
  counter increment, or other integration callback.
- Error traces now render every named SQL binding parameter instead of
  truncating the parameter list after five values. Host sanitization still runs
  before the details are logged or rendered.
