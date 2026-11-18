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

### Added

- `unregister()` restores the previous error handler (with its type mask), the
  previous exception handler, and every ini value `register()` changed.
  `isRegistered()` reports the state.
- On PHP 8.5+, a fatal error's backtrace from `error_get_last()` is used when
  PHP provides one.

### Fixed

- PHP-suppressed diagnostics now return `false` from `handleError()` under every
  policy and skip decision, so `error_get_last()` still sees them.
- Debug-bar messages now pass through `sanitizeErrorDetails()`.
- Calling `register()` twice no longer stacks a second error handler,
  exception handler or shutdown callback.

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
