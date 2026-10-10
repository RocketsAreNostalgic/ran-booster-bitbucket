# Contributing

Follow the [shared contribution guidance](https://github.com/RocketsAreNostalgic/.github/blob/main/CONTRIBUTING.md) and the repository-specific development instructions below.

Use a Conventional Commit pull-request title (`feat:`, `fix:`, `docs:`, `test:`, `chore:`) so the squash commit subject consumed by Release Please truthfully represents the change.

Before proposing a change, run `composer check` for the Core-independent source-quality contract. Host checks use the exact immutable Core beta.31 checkout recorded in
`extra.ran-booster-core-certification.commit`. Install only its locked production
dependencies, leave candidate mode unset, and run both host checks and analysis:

```sh
unset RAN_BOOSTER_CORE_TEST_MODE
export RAN_BOOSTER_CORE_PATH=../ran-booster
export RAN_BOOSTER_CORE_VENDOR_AUTOLOAD=../ran-booster/vendor/autoload.php
composer check:host
composer analyze
```

The add-on test suite consumes only that Core checkout's shipped `autoload.php` and public production contracts; do not install Core's development dependencies or import Core-owned test fixtures into this repository. Run `composer build:release` for archive verification. These released-host checks qualify source merges after independent review; they do not replace certification against a matching immutable Core release or installed proof. The release caller requires that proof against the exact successful main Quality SHA before shared Profile B admission. Changes to compatibility, the entry header, version sources, archive allowlist, or release documentation need a matching test or archive verification update.

This add-on is distributed through verified GitHub release artifacts only. Do not add WordPress.org/SVN release work without a separate decision.

Use ordinary issues for support and non-sensitive defects. Follow
[SECURITY.md](SECURITY.md) for vulnerabilities; do not submit security details
in an issue or pull request.

## Published coding standard

Development tooling uses `ran/coding-standards` `^1.0.1`, locked to published
v1.0.1 (`0248066be3f4f9476ef7095d888657001488a3de`). Install from the lockfile;
upgrades require their own dependency diff and retained local/native proof.
The shared profile makes assignment/array alignment blocking even when PHPCS
warnings are hidden. `standards` and `standards:fix` remain the same authority.
This repository also enables `RANOwnedMethods` for owned method naming; see
`.phpcs.xml`. Dependency adoption does not change the certified host.

## Syntax command integrity

`composer lint:syntax` first completes NUL-delimited file discovery, then parses
one file at a time. Discovery, empty selection and parser failures fail the
command. Vendor and PHPStan/PHPUnit caches remain excluded. `composer test:syntax`
exercises the canonical command in a disposable fixture, including spaces and
newlines in filenames, excluded invalid files, invalid PHP and failed discovery
before or after a filename is emitted. It runs in the independent `composer check`
and therefore also in `composer check:host` and native source-quality CI.

`composer check:coverage` discovers maintained product PHP in the repository,
excluding tests, scripts and generated/dependency directories. It checks that
each discovered file is directly selected by both the configured PHPStan paths
and the shared PHPCS/PHPCBF ruleset. New root or nested product PHP therefore
fails until its analysis and standards scope is reviewed. Unexpected local
analysis includes/exclusions, a non-PHP file extension list or standards
exclusions (patterns and ignore arguments) also fail closed. The
guard requires the reviewed Composer tool commands verbatim, so positional
PHPStan paths and CLI overrides cannot quietly replace configured selection;
PHPStan's locked NEON adapter parses scope keys and values; unsupported PHP
extension case variants fail before they can evade the lowercase PHP tools. The
`composer test:coverage` disposable fixture proves that the required `check`
rejects a new root product file, narrowed analysis or standards scope and a
PHPCS exclusion; `check:host` inherits this repository check. The release
verifier and purpose-built test fixtures now share the PHPCS/PHPCBF profile
with the documented role-specific exceptions. PHPStan analyzes product and
maintained development PHP in separate level-8 profiles; `composer check:host`
runs both.

## Condition-style enforcement

The WordPress Yoda-condition rule is enforced across the configured production
PHPCS paths. `standards` and `standards:fix` use the same ruleset. The pagination
collection-path comparison retains strict inequality and the existing parse
failure short circuit; only operand order changed. Existing pagination tests
pass before and after the change. Restoring the old comparison fails the
configured standards gate, and two clean PHPCBF passes leave source unchanged.
Owned method/variable naming is enforced across all configured product paths,
including future classes and classes with inherited or implemented contracts.
The released API 14 contract requires no legacy Core naming exceptions.

## Production naming enforcement

`RANOwnedMethods` and the shared WordPress naming rules cover `src/`, `views/`,
`autoload.php`, `ran-booster-bitbucket.php` and `index.php`: all 20 currently
shipped PHP files. Check and fix use this same scope; new PHP files under the
configured directories are included automatically. The same profile also
covers maintained development PHP under `tests/` and `scripts/`, including the
HTTP `.stub` contract, with the
diagnostic-specific foreign-signature and CLI exceptions described below.

Unused-parameter checks follow the shared WPCS baseline, including its upstream
inherited-signature allowances. PHP magic methods retain their required spelling.

## Provider API 14 connected naming

The released Core API 14 generation includes the earlier 28 interface method migrations
and completes owned parameter, DTO property, request/result method and consumer
naming. Normal host qualification uses `extra.ran-booster-core-certification.commit`;
the earlier candidate identity remains a test-only historical fixture.

All obsolete declaration, variable and property naming waivers have been removed.
`composer test:naming` retains the earlier 28-method negative controls: each old
spelling is restored in a disposable copy and rejected by `RANOwnedMethods`.
The broader production PHPCS scope checks the remaining recovered naming, and
level-8 host analysis checks the actual released contract.

Public named arguments now use `credential_id`, `repository_authority_id` and
`repository_locator`. Core DTO fields include
`AuthenticatedWebhookDeliveryEvidence::matched_managed_package` and
`RepositoryLookupRequest::credential_id` / `public_only`. Request/result methods
include `WebhookRequest::get_provider` and
`SignedWebhookVerification::get_provider`. Validator optional `timeout` and
`response_size` retain their names, types and defaults.

No coexistence aliases are provided during beta. Coordinate validation,
authorization, case-insensitive matching, response bytes/status, wire keys,
URL/path/query restrictions, HTTP classification, malformed-response guards,
pagination, repository/ref/commit identity, archive authentication and cleanup
are preserved. Provider API advances to 14 and Add-on API to 17; Workflow V3 is
unchanged. Core beta.31 now supplies the matching immutable released host.

## Blocking host analysis

PHPStan level 8 is required by `composer check:host` and the full repository CI
lane, whose failure feeds terminal `Quality`. The independent `composer check`
remains usable without Core. The focused command also fails on analysis errors.
Run it in default released mode against the recorded Core production source; Core's own Composer
dependencies are optional for this focused command:

```sh
RAN_BOOSTER_CORE_PATH=../ran-booster composer analyze
```

The lockfile currently resolves PHPStan 2.2.8, `phpstan-wordpress` 2.0.3 and
WordPress stubs 6.9.4. Direct level-8 roots are `autoload.php`, the plugin
entrypoint, `src/`, `views/` and `index.php`: all 20 currently shipped PHP files
selected by `release-files.txt`. Directory roots include future PHP files under
`src/` and `views/`. The inert index is included for complete shipped-path
coverage. Host/bootstrap symbol availability is not additional direct coverage;
tests, fixtures and the unshipped release verifier are directly analyzed by
`phpstan-development.neon.dist` instead. That level-8 profile starts at the
repository root and excludes only the separately analyzed production paths and
dependency/generated roles. New development PHP is selected automatically.
`composer analyze:development` runs it independently; `composer check:host` runs
both profiles plus `composer test:development-analysis`. The effective-coverage
guard checks their union, level floors and stub filtering. The HTTP `.stub` is
directly analyzed as development PHP and validated as a product stub; fixture
symbols remain isolated from product analysis.

Analysis uses `--debug` to keep the commands serial in managed environments that
prohibit PHPStan's loopback worker socket. No baseline or broad ignore list is
accepted. Locked PHPStan API calls and the executable JSON type assertion retain
occurrence-specific annotations and adjacent-call/assertion negative controls,
as described in `AGENTS.md`.

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
The locked WordPress stubs cover the symbols used by this add-on, with the
filterable HTTP return correction above. Their 6.9.4 version is not a claim of
complete WordPress 7.0 API coverage: retain the native WordPress 7.0.3 installed
proof and review stub accuracy when adopting new WordPress APIs or tool versions.

The three historical `BitbucketArchivePreparer::resolvedNamedRef()` findings
were redundant outer `is_array($data)` checks against its native `array`
parameter. Those checks are now removed; nested target/repository shape checks,
name/hash validation and repository identity checks remain. Null/scalar JSON
is rejected before that typed method, and malformed nested values still fail
without installing archive hooks. Regression fixtures pass before and after
the cleanup.

The missing return-array shape on `Plugin::documentation_sections()` is now
documented to match its parameter and appended section.

For future analysis-tool or level changes:

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

## Development standards

The v1.0.1 development-only upgrade adopts the shared exception-message policy:
`WordPress.Security.EscapeOutput.ExceptionNotEscaped` is intentionally disabled
by the shared profile because throwing an exception is not rendering output.
Thirteen redundant local directives are removed; provider error mapping and
actual output escaping remain unchanged. All other locked packages, the
required PHPStan level 8 gate and immutable Core beta.31 certification remain
unchanged.

Standards and analysis cover all 50 maintained PHP-bearing files, including
the HTTP `.stub`. Product and development analysis both require level 8.

Owned test/helper methods and local variables use snake_case, including inherited
PHPUnit test methods. PHPUnit lifecycle overrides, native DOM/ZipArchive fields,
and the ProviderCredentialStore test-double signature retain exact inline
exceptions. The three former blanket suppressions are removed. Standalone CLI
filesystem/process operations and inert WordPress fixture behavior have named,
explained diagnostic exceptions. Prefix diagnostics have concrete inline or file-scoped annotations for PHPUnit
namespaces, CLI processes, foreign functions/constants and installed foreign
hooks. They cannot leak into similarly named product subdirectories and do not
disable owned local/method naming or product prefix checking.

`check:coverage` rejects new unselected development PHP and blanket PHPCS comments
using PHP tokens. It still rejects product exclusions and unsupported analysis
configuration; all PHPCS configuration exclusions are rejected. `test:naming` exercises the real standards commands on future test
and CLI files, inherited methods, removed development scope, and line/block/doc
blanket annotations. Two clean fixer passes must preserve tracked bytes.

This does not by itself declare full organisation #128 acceptance: retained
product exceptions still require grouped disposition. Runtime PHP, dependency
locks, API identities and genuine released-Core certification are unchanged.

## Narrow standards exemptions

The suppression guard reads PHP comment tokens, accepts only exact four-part
PHPCS diagnostic codes with a non-empty reason, and rejects category/standard
selectors, legacy directives, case/prefix variants of file ignores and inline
configuration changes. Only
`WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound` may remain
disabled at line 2, and only in these seven guard-enumerated paths:

- `scripts/check-product-php-coverage.php`
- `scripts/verify-release.php`
- `tests/bootstrap.php`
- `tests/phpstan-bootstrap.php`
- `tests/fixtures/plugin-lifecycle.php`
- `tests/WordPress/bitbucket-installed-inert.php`
- `tests/WordPress/bitbucket-installed-smoke.php`

New files must comply or receive a deliberate reviewed scope change. This
exemption does not cover declarations or foreign hooks. Existing foreign WordPress/Core
names have occurrence-local diagnostic annotations; the release verifier's
owned constants and functions use the repository prefix.

These are mechanical scope controls, not automatic approval of a new reason.
New exemptions still require review and an accepted invariant under organisation
#65/#128. Do not infer exemption acceptance from a green standards report. The
real-checker controls demonstrate broad annotations hiding a violation while
the independent guard rejects them, and an exact annotation leaving its adjacent
violation visible. Production analysis remains level 8, with independently
discovered product PHP failing the gate if omitted from direct analysis.

The XML guard also pins the reviewed command arguments: `exclude` and `sniffs`
arguments can otherwise remove required diagnostics without an XML exclusion.
Locked-checker controls reproduce both bypasses. Coverage now compares its
independent product inventory with PHPStan's effective file finder, so lexical
path membership cannot certify an omitted dot-file. PHP entrypoints outside lowercase `.php`
are rejected for an explicit scope decision instead of silently being omitted.

Effective analysis coverage mirrors PHPStan's post-discovery stub-file filtering;
reclassifying maintained production PHP as a stub fails the coverage gate.

The bounded header check recognizes ordinary/uppercase PHP open tags and short
echo tags, with optional shebang, including alternate extensions such as `.inc`.

Owned PHPUnit namespaces use `RAN\Booster\Bitbucket\Tests`; their previous `Tests` identities were
local choices, so they no longer have namespace-prefix exemptions. The exact
`RAN\RepositoryProvider` interception namespace remains unchanged with its precise foreign-namespace
exemption. The real-checker naming control rejects restoring
an unprefixed owned test namespace. No production code or dependencies change.
