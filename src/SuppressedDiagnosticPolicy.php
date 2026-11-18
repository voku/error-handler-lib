<?php

declare(strict_types=1);

namespace voku\ErrorHandlerLib;

/**
 * How the host wants PHP-suppressed diagnostics to be treated.
 *
 * A diagnostic is "suppressed" when the `error_reporting()` state that is active while the
 * diagnostic is raised does not contain its type. That covers both the `@` operator and an
 * explicit `error_reporting()` mask; the library does not try to tell those two apart.
 *
 * PHP suppression is deliberately kept separate from the application-level
 * {@see ErrorHandlerSkipDeciderInterface} decision:
 * this policy answers "is a diagnostic PHP hid from me still interesting?", the skip decider
 * answers "is this specific diagnostic intentionally ignorable?".
 *
 * The policy is never consulted for critical/fatal diagnostics or for uncaught exceptions.
 *
 * The policy decides whether a suppressed diagnostic is processed, not how. A processed one is
 * handled exactly like a reported one of the same type - in particular, notices and deprecations are
 * only logged or rendered when {@see ErrorHandlerIntegrationInterface::isTestingEnvironment()} is true.
 *
 * Whatever the policy, the handler returns `false` for a suppressed non-critical diagnostic, so PHP
 * still records it for `error_get_last()` without displaying or logging it. A critical diagnostic
 * goes to the non-returning critical renderer instead, suppressed or not.
 */
enum SuppressedDiagnosticPolicy
{
    /**
     * Handle suppressed diagnostics exactly like reported ones.
     *
     * This is the default and the reason the library exists: a `@file_get_contents(...)` that
     * silently fails stays visible during development and CI instead of costing debugging time.
     */
    case Observe;

    /**
     * Handle suppressed diagnostics, but never render them into the output.
     *
     * Logging and debug-bar recording happen as they would for a reported diagnostic (so notices
     * and deprecations are still only logged in a testing environment). The evidence survives
     * without a suppressed warning turning into a prominent stack trace in a response or tool run.
     */
    case LogOnly;

    /**
     * Apply PHP's own suppression semantics: do not process the diagnostic at all.
     *
     * No logging, no debug-bar entry, no rendering, no counters, no integration callbacks.
     */
    case Ignore;
}
