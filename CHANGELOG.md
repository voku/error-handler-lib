# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

### Breaking changes

- `ErrorHandlerIntegrationInterface` now requires `includeTraceArguments(): bool`.
  Stack traces no longer render argument values unless it returns `true`,
  and `register()` only sets `zend.exception_ignore_args=Off` in that case.
  Return `true` to keep the previous output.
- `ErrorHandlerIntegrationInterface` now also requires
  `recordSuppressedDiagnosticsInErrorGetLast(): bool`. With `true` (the default
  integration's answer) a PHP-suppressed non-critical diagnostic is returned to
  PHP, so `error_get_last()` sees it. Return `false` to keep the previous
  behaviour, where the handler claimed it and `error_get_last()` stayed
  untouched.
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

### Upgrading existing applications (compatibility recipes)

- **Custom integrations:** implement the new method below to retain legacy
  trace argument rendering. Prefer returning `false` unless the application
  explicitly needs argument values; returning `true` can expose credentials
  and personal data and enables exception argument collection during registration.
  This restores argument rendering, not the old report format.

  ```php
  public function includeTraceArguments(): bool
  {
      return true; // Legacy behavior; prefer false for new integrations.
  }
  ```

- **Default integration and skip decider:** if constructor injection previously
  configured static helpers or subsequently constructed instances, explicitly
  configure the defaults before registration (with your existing integration
  and skip decider):

  ```php
  ErrorHandlerLib::useIntegration($integration, $skipDecider);
  $handler = (new ErrorHandlerLib())->register();
  ```

  Pass the existing skip decider when needed; omitting it resets the default
  skip decider to the no-op implementation.

- **Suppressed diagnostics:** return `SuppressedDiagnosticPolicy::Observe` from
  `suppressedDiagnosticPolicy()` to retain legacy observation and reporting.
  Code bases that suppress routinely (`@file_get_contents()` everywhere) and
  whose shutdown code or monitoring reports whatever `error_get_last()` holds
  should keep the old return behaviour:

  ```php
  public function recordSuppressedDiagnosticsInErrorGetLast(): bool
  {
      return false; // Legacy: suppressed diagnostics do not reach error_get_last().
  }
  ```

  Return `true` for PHP's own semantics, so that code like
  `if (@fopen($path, 'rb') === false) { $error = error_get_last(); }` works.
  The flag changes only what PHP records; the library processes the
  diagnostic according to the policy either way. Critical diagnostics and the
  `Ignore` policy are unaffected.
- **Explicit Throwable reporting:** `reportError($throwable)` and
  `reportWarning($throwable)` intentionally omit the throwable's original
  trace. If an existing consumer requires the old text format, pass an
  explicitly formatted, redacted string instead. Passing
  `$throwable->__toString()` would reproduce the old diagnostic text but can
  expose argument values before library sanitization; do not do so with
  sensitive data. String inputs and diagnostic severities are unchanged.
- **Report consumers:** update parsers that read the old `previous_error`
  context field to consume the new previous-throwables section. There is no
  legacy-format switch. Review custom sanitizers for the new format and for
  debug-bar output, which now also passes through the sanitizer.
- **Handler ownership:** register once per instance and use `unregister()`
  in reverse registration order when temporarily installing handlers.
  Applications relying on duplicate registrations or the oldest instance
  handling shutdown fatals must adjust; those behaviors are not configurable.
  Set any application-wide INI policy explicitly outside the handler lifecycle
  rather than relying on registration side effects.

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
  Values are compared the way PHP parses them (`" on"` is off, `display_errors=2`
  is stderr).
- A compile error that the script recovered from (`opcache_compile_file()`) is
  no longer reported as a fatal error at shutdown, also when the recovery
  happens inside an earlier shutdown function.
- `register()` called from inside an integration hook of the same instance no
  longer installs a second handler pair.
- A diagnostic raised while a chained exception's report is being built no
  longer carries that exception's previous-throwable chain.
- `display_errors` is read the way PHP reads it when deciding whether PHP should
  display a diagnostic too (`handleError()`'s return value) and in
  `ErrorHandlerDefaultIntegration::shouldDisplayErrors()`. Previously
  `display_errors=stderr`, `=stdout` and `=2` counted as off, so the default
  integration's `register()` switched a stderr setup to `Off`; `" on"` counted
  as on, so the library rendered errors that PHP itself would not display.
  A numeric mode counts by its low byte, as in PHP (`256` is off, `258` is
  stderr).
- Anonymous class names no longer truncate log entries. Their NUL byte made
  `error_log()` drop everything after the name; the library now renders them
  the way PHP does (`class@anonymous`).
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
