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
WordPress stubs 6.9.4. The configuration analyzes only `autoload.php`, the
plugin entrypoint and `src/` at level 3. It uses `--debug` to keep the command
serial and reproducible in managed development environments that prohibit
PHPStan's loopback worker socket. It uses no baseline, ignored-error rule or
production annotation. On 27 September 2026, the unchanged level-3 roots (18
PHP files) reported zero errors with the locked tools against certified Core
`ffc11fc8e40618624a785b7fca5193029c6d492e` (beta.29). This promotion changes
enforcement only; views remain outside the recorded analysis roots and require
a separate coverage decision. No source, dependency or certified-host pin changes.

The pilot measured these higher-level results on 11 August 2026:

| Levels | Findings | Interpretation |
| --- | ---: | --- |
| 3 | 0 | Adopted clean floor. |
| 4-5 | 14 | Eleven HTTP-response certainty findings plus three defensive array checks. |
| 6-8 | 15 | The same findings plus one missing iterable return-value annotation. |

The eleven `BitbucketApiClient` findings arise because the WordPress stubs model
`wp_remote_get()` success as a guaranteed complete response shape. The runtime
deliberately validates malformed transport values and must not be weakened to
match that optimistic model. Three `BitbucketArchivePreparer::resolvedNamedRef()`
findings identify `is_array()` checks against its native `array` parameter; they
are redundant to the signature but remain part of the defensive response
validation sequence. Level 6 also identifies a genuine documentation
opportunity: `Plugin::documentationSections()` has a typed parameter shape but
no matching iterable value type on its return annotation.

Before raising the level, a follow-up must:

1. model intentionally malformed WordPress HTTP responses without weakening
   runtime checks or applying a broad identifier ignore;
2. decide explicitly whether the three redundant array guards should remain or
   be removed with their behavior tests;
3. add and verify the documentation-section return shape if level 6 is still
   useful;
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
