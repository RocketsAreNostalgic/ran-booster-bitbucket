# Contributing

Use a Conventional Commit pull-request title (`feat:`, `fix:`, `docs:`, `test:`, `chore:`) so the squash commit subject consumed by Release Please truthfully represents the change.

Before proposing a change, run `composer check` for the Core-independent source-quality contract. Then check out the exact Core release recorded in `extra.ran-booster-core-certification`, set `RAN_BOOSTER_CORE_PATH` to that checkout, run `composer check:host` for the Core-backed unit and release-candidate contracts, and run `composer build:release`. The add-on test suite consumes only that Core checkout's shipped `autoload.php` and public production contracts; do not install Core's development dependencies or import Core-owned test fixtures into this repository. Changes to compatibility, the entry header, version sources, archive allowlist, or release documentation need a matching test or archive verification update.

This add-on is distributed through verified GitHub release artifacts only. Do not add WordPress.org/SVN release work without a separate decision.

Use ordinary issues for support and non-sensitive defects. Follow
[SECURITY.md](SECURITY.md) for vulnerabilities; do not submit security details
in an issue or pull request.

## Published coding standard

Development tooling uses `ran/coding-standards` `^1.0`, locked to published
v1.0.0 (`6af816a02b7d1108ad5c990e9d0fda0af0a13de7`). Install from the lockfile;
upgrades require their own dependency diff and retained local/native proof.
The shared profile makes assignment/array alignment blocking even when PHPCS
warnings are hidden. `standards` and `standards:fix` remain the same authority.
The separately available `RANOwnedMethods` profile is opt-in; this adoption
alone does not activate unfinished naming scopes or change the certified host.

## Syntax command integrity

`composer lint:syntax` first completes NUL-delimited file discovery, then parses
one file at a time. Discovery, empty selection and parser failures fail the
command. Vendor and PHPStan/PHPUnit caches remain excluded. `composer test:syntax`
exercises the canonical command in a disposable fixture, including spaces and
newlines in filenames, excluded invalid files, invalid PHP and failed discovery
before or after a filename is emitted. It runs in the independent `composer check`
and therefore also in `composer check:host` and native source-quality CI.

## Condition-style enforcement

The WordPress Yoda-condition rule is enforced across the configured production
PHPCS paths. `standards` and `standards:fix` use the same ruleset. The pagination
collection-path comparison retains strict inequality and the existing parse
failure short circuit; only operand order changed. Existing pagination tests
pass before and after the change. Restoring the old comparison fails the
configured standards gate, and two clean PHPCBF passes leave source unchanged.
Owned method/variable naming is enforced for all sixteen current product class
files listed below, with narrow certified Core exceptions. Future-file scope and
final exception disposition remain with the owning programme.

## Scoped owned-name enforcement

`RANOwnedMethods` and WordPress variable naming cover exactly
`BitbucketApiResponse.php`, `BitbucketRepositoryCoordinates.php`,
`BitbucketCredential.php`, `BitbucketCredentialLoader.php`,
`BitbucketCredentialException.php`, `BitbucketApiClient.php`,
`BitbucketApiException.php`, `BitbucketRepositoryBrowser.php`,
`BitbucketArchivePreparer.php`, `BitbucketDiagnostics.php`, `Plugin.php`,
`BitbucketCredentialPolicy.php`, `BitbucketWebhookPolicy.php`,
`BitbucketWebhookNormalizer.php`, `BitbucketProvider.php` and
`BitbucketCredentialValidator.php`.
Constructors retain their required magic spelling. All current product class
files now have selected enforcement with the narrow
Core contract exceptions below. Future-file scope and final exclusion disposition
remain under #63 / organisation #65; preserve Core-required signatures.
Tests and purpose-built fixtures keep their existing separate scope.

| Owned scope | Owned-name migration |
| --- | --- |
| `BitbucketApiResponse` | `getStatus` / `getBody` become `get_status` / `get_body`; constructor names `status` and `body` are unchanged. |
| `BitbucketRepositoryCoordinates` | `fromFullName`, `getWorkspace`, `getRepositorySlug`, `getFullName`, `matchesFullName` become their snake_case equivalents; owned `repositorySlug` / `fullName` become `repository_slug` / `full_name`, including factory/matcher named arguments. |
| `BitbucketCredential` | `fromMaterial`, `getWorkspace`, `isWorkspace` become `from_material`, `get_workspace`, `is_workspace`; validation and Authorization header bytes stay unchanged. |
| `BitbucketCredentialLoader` / `BitbucketCredentialException` | `load` takes `credential_id` (including named arguments); `unavailable` is already compliant. Core `credentialMaterial` remains unchanged. |
| `BitbucketApiClient` | Private URL/query helpers become snake_case; `responseSize` / `decodedPath` become `response_size` / `decoded_path`, including the public `get` named argument. |
| `BitbucketApiException` | `invalidUrl`, `transportError`, `invalidResponse`, `getReason` become snake_case. Reason constants, error messages and inherited exception methods stay unchanged. |
| `BitbucketRepositoryBrowser` | Six private helpers and owned parameters/locals become snake_case; `repository` named arguments are `full_name`, `credential_id`, `timeout`, `response_size`, `public_only`. Core DTO properties and browse-request methods remain unchanged. |
| `BitbucketArchivePreparer` | `prepareArchive` and eleven private helpers become snake_case, with owned parameters/locals and closure captures. Core-required `BitbucketProvider::prepareArchive`, archive methods, request fields and hook/wire names remain unchanged. |
| `BitbucketDiagnostics` | Five private helpers and the owned credential local become snake_case. Core diagnostic request/result methods and the public `diagnose` signature remain unchanged. |
| `Plugin` | Registration, documentation and compatibility callbacks, their registered method strings, the compatibility helper and owned parameters become snake_case. Hook names, priority/argument counts, Core registration methods and rendered output remain unchanged. |
| `BitbucketCredentialPolicy` / `BitbucketWebhookPolicy` | Nine private helpers and three owned locals become snake_case. All twelve public declarations retain their certified Core interface names; webhook authorization and target matching also retain the two interface parameter names. |
| `BitbucketWebhookNormalizer` | Eight private helpers, two owned properties/constructor parameters and three locals become snake_case. Constructor named arguments are `webhook_profiles` and `delivery_evidence`. Core interface methods and authenticated-delivery evidence fields remain unchanged. |
| `BitbucketProvider` / `BitbucketCredentialValidator` | Provider properties become `credential_validator` / `credential_policy`, including the owned constructor parameter. Validator's extra optional parameter becomes `response_size`; Core-required `credentialId` stays unchanged. Public interface methods and request fields are retained. |

The browser/archive scope retains nine line-specific
`UsedPropertyNotSnakeCase` suppressions for eleven accesses to certified Core
`RepositoryDescriptor`, `RepositoryReference` and `ArchiveRequest` fields
(`providerRepositoryId`, `credentialId`, `expectedBranch`). These are temporary
connected-contract exceptions under Core #167, not exceptions for locally owned
variables or methods. Remove them with the qualified Core field migration.

The two policy files retain declaration-specific `NotSnakeCase` suppressions
for the twelve `ProviderCredentialPolicy` / `ProviderWebhookPolicy` methods.
Five line-specific `VariableNotSnakeCase` suppressions preserve the declarations
and uses of `repositoryAuthorityId` / `repositoryLocator`, preserving named-argument
compatibility. These temporary certified-contract exceptions belong to Core #167;
remove them with its qualified connected signature migration. Owned helpers and
locals remain enforced; no whole-method or whole-class exception is used.

`BitbucketWebhookNormalizer` retains three declaration-specific `NotSnakeCase`
suppressions for its certified Core interface methods and one line-specific
`UsedPropertyNotSnakeCase` suppression for
`AuthenticatedWebhookDeliveryEvidence::matchedManagedPackage`. Remove these
with Core #167's qualified connected signature/field migration. Its own properties,
constructor parameters, locals and private helpers remain enforced.

Provider/Validator retain thirteen declaration-specific `NotSnakeCase`
suppressions for their certified Core interface methods, four line-specific
`VariableNotSnakeCase` suppressions for `credentialId` declarations/uses, and
two `UsedPropertyNotSnakeCase` suppressions for `RepositoryLookupRequest` fields
`credentialId` / `publicOnly`. Core #167 owns removal with its qualified connected
signature/field migration. The Validator-only optional timeout/response-size
arguments are not part of Core's one-argument interface.

No coexistence aliases are provided during beta. Audited callers move with these
methods; similarly named request and Core methods are unchanged.
Coordinate validation, case-insensitive matching, response bytes/status, wire
keys, URL/path/query restrictions, HTTP classification, malformed-response guards,
pagination/budgets/partial results,
repository/ref/commit identity, archive authentication and cleanup,
host API generation and the certified Core tuple remain unchanged.

## Blocking host analysis

PHPStan level 8 is required by `composer check:host` and the full repository CI
lane, whose failure feeds terminal `Quality`. The independent `composer check`
remains usable without Core. The focused command also fails on analysis errors.
Run it against the exact certified Core production source; Core's own Composer
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
tests, fixtures and the unshipped release verifier remain outside these roots.

Analysis uses `--debug` to keep the command serial in managed environments that
prohibit PHPStan's loopback worker socket. It uses no baseline, ignored-error
rule or production annotation. On 27 September 2026, all 20 paths reported zero
errors with the locked tools against certified Core
`ffc11fc8e40618624a785b7fca5193029c6d492e` (beta.29). A temporary return-type
violation in the documentation view failed `check:host` during analysis, before
PHPUnit; the probe was removed. That coverage extension retained the then-required
level 3, production bytes, dependency locks and the certified-host tuple.

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
Levels 3–8 report zero findings across all 20 shipped PHP paths. The required
gate is now level 8; the promotion changes configuration and guidance only.
On 28 September 2026, each intermediate level 3–8 was rerun separately with
zero findings against the same certified Core and locked tools. A temporary
nullable `DateTimeImmutable` method-call probe passed levels 3 and 7, but failed
the default `composer analyze` and `composer check:host` at level 8 before tests.
The probe was removed and the clean host aggregate rerun.

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
