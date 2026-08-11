# Contributing

Use Conventional Commits (`feat:`, `fix:`, `docs:`, `test:`, `chore:`) so Release Please can prepare the changelog and version proposal.

Before proposing a change, run `composer check` with a compatible sibling RAN Booster checkout, then run `composer build:release`. Changes to compatibility, the entry header, version sources, archive allowlist, or release documentation need a matching test or archive verification update.

This add-on is distributed through verified GitHub release artifacts only. Do not add WordPress.org/SVN release work without a separate decision.

## Static analysis pilot

PHPStan is adopted as a reproducible, non-blocking Bitbucket-only command. It
is intentionally separate from `composer check` while the pilot gathers useful
signal. Run it against a compatible Core checkout:

```sh
RAN_BOOSTER_CORE_PATH=../ran-booster composer analyse
```

The lockfile currently resolves PHPStan 2.2.8, `phpstan-wordpress` 2.0.3 and
WordPress stubs 6.9.4. The configuration analyzes only `autoload.php`, the
plugin entrypoint and `src/` at level 3. It uses `--debug` to keep the command
serial and reproducible in managed development environments that prohibit
PHPStan's loopback worker socket. The measured clean run takes approximately
2.5 seconds and uses no baseline, ignored-error rule or production annotation.

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
raise the reported level. Promotion into `composer check`, use by a second
component or family-wide dependency alignment requires a separate evidence-led
decision.
