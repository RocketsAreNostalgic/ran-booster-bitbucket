# Contributing

Use a Conventional Commit pull-request title (`feat:`, `fix:`, `docs:`, `test:`, `chore:`) so the squash commit subject consumed by Release Please truthfully represents the change.

Before proposing a change, run `composer check` for the Core-independent source-quality contract. Then check out the exact Core release recorded in `extra.ran-booster-core-certification`, set `RAN_BOOSTER_CORE_PATH` to that checkout, run `composer check:host` for the Core-backed unit and release-candidate contracts, and run `composer build:release`. The add-on test suite consumes only that Core checkout's shipped `autoload.php` and public production contracts; do not install Core's development dependencies or import Core-owned test fixtures into this repository. Changes to compatibility, the entry header, version sources, archive allowlist, or release documentation need a matching test or archive verification update.

This add-on is distributed through verified GitHub release artifacts only. Do not add WordPress.org/SVN release work without a separate decision.

Use ordinary issues for support and non-sensitive defects. Follow
[SECURITY.md](SECURITY.md) for vulnerabilities; do not submit security details
in an issue or pull request.

## Blocking host analysis

PHPStan level 3 is required by `composer check:host` and the full repository CI
lane, whose failure feeds terminal `Quality`. The independent `composer check`
remains usable without Core. The focused command also fails on analysis errors.
Run it against the exact certified Core production source; Core's own Composer
dependencies are optional for this focused command:

```sh
RAN_BOOSTER_CORE_PATH=../ran-booster composer analyze
```

The lockfile currently resolves PHPStan 2.2.8, `phpstan-wordpress` 2.0.3 and
WordPress stubs 6.9.4. Direct level-3 roots are `autoload.php`, the plugin
entrypoint, `src/`, `views/` and `index.php`: all 20 currently shipped PHP files
selected by `release-files.txt`. Directory roots include future PHP files under
`src/` and `views/`. The inert index is included for complete shipped-path
coverage. Host/bootstrap symbol availability is not additional direct coverage;
tests, fixtures and the unshipped release verifier remain outside these roots.

Analysis uses `--debug` to keep the command serial in managed environments that
prohibit PHPStan's loopback worker socket. It uses no baseline, ignored-error
rule or production annotation. On 27 September 2026, all 20 paths reported zero
errors with the locked tools against certified Core
`ffc11fc8e40618624a785b7fca5193029c6d492e` (beta.29). A temporary return-type
violation in the documentation view failed `check:host` during analysis, before
PHPUnit; the probe was removed. This coverage extension preserves level 3,
production bytes, dependency locks and the certified-host tuple.

The historical pilot measured these higher-level results on 11 August 2026
(the pre-fix counts were reverified across all 20 shipped paths on 28 September
2026 at `80895ee9ffe0bf4e49b38939115c8946438f5a60`):

| Levels | Findings | Interpretation |
| --- | ---: | --- |
| 3 | 0 | Adopted clean floor. |
| 4-5 | 14 | Eleven HTTP-response certainty findings plus three defensive array checks. |
| 6-8 | 15 | The same findings plus one missing iterable return-value annotation. |

The historical HTTP-client findings combined ten optimistic response-shape
assumptions with one `method_exists()` check after `is_wp_error()` narrowing.
The analysis-only `tests/phpstan/wordpress-http.stub` now models the return of
`wp_remote_get()` as `mixed`: WordPress's
[pre_http_request filter](https://developer.wordpress.org/reference/hooks/pre_http_request/)
returns a non-false filter value early, including malformed values. The add-on
must validate that boundary before using the response. This is a correction to
the external boundary model, not a production cast or a suppressed diagnostic.

The stub is registered through PHPStan `stubFiles`, never executed or shipped.
Its request argument shape mirrors locked wordpress-stubs 6.9.4; unspecified
array values are explicitly `mixed` for stub validation. Reconcile that copied
shape whenever updating WordPress stubs. Only `wp_remote_get()` is overridden;
`is_wp_error()` keeps its upstream WP_Error narrowing, and no global PHPDoc
certainty setting changes. Negative probes still reject invalid URL/timeout
types and calls to nonexistent methods on a narrowed WP_Error.

The existing and expanded malformed-response tests retain fixed safe errors for
null/scalar results, missing/malformed nested response data and non-string bodies.
The modelling slice itself changed no production PHP. The subsequent cleanup
removes only the redundant `method_exists()` guard after `is_wp_error()`:
WordPress narrows that branch to `WP_Error`, whose public `get_error_code()`
method is always available. The existing namespaced test double supplies the
same method. Only `http_request_failed` becomes a transport error; other codes,
including an empty code, still become fixed safe invalid-response errors.
Non-error objects still reach the malformed-response guard. The expanded
classification fixtures pass before and after the cleanup.
Levels 3–8 now report zero findings across all 20 shipped PHP paths; the
required gate stays at level 3 pending separately reviewed promotion.

The three historical `BitbucketArchivePreparer::resolvedNamedRef()` findings
were redundant outer `is_array($data)` checks against its native `array`
parameter. Those checks are now removed; nested target/repository shape checks,
name/hash validation and repository identity checks remain. Null/scalar JSON
is rejected before that typed method, and malformed nested values still fail
without installing archive hooks. Regression fixtures pass before and after
the cleanup.

The missing return-array shape on `Plugin::documentationSections()` is now
documented to match its parameter and appended section. The required gate remains level 3.

Before raising the level, a follow-up must:

1. retain the WP_Error method contract and the transport error classification
   evidence described above;
2. preserve the nested response guards and the regression coverage for the
   removed native-array redundancies;
3. retain the documented section return shape and verify it at the proposed
   level;
4. confirm the stable WordPress extension provides stubs appropriate for the
   plugin's WordPress 7.0+ support; and
5. rerun every intermediate level and record the remaining finding count.

Do not generate a baseline, add per-line suppressions, introduce casts, widen
types, remove hostile-input checks or add production abstractions merely to
raise the reported level. Expanding analysis coverage, raising levels, use by a
second component or family-wide dependency alignment requires a separate
evidence-led decision.
The full repository lane now runs the host aggregate without an advisory
`continue-on-error` bypass. Release-candidate lanes retain their existing
exact-successful-main admission and installed-proof requirements.
