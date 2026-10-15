# Changelog

All notable changes to this project are documented in this file.

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
