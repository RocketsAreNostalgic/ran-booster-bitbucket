# RAN Booster Bitbucket add-on

This repository is a dependent RAN Booster add-on, not a standalone plugin.

## Runtime contract

- RAN Booster is required. This add-on supports exactly Provider API `14` and the official-suite Add-on API `17` generation, WordPress 7.0+, and PHP 8.2+; PHP 8.4 is recommended, not required.
- The exact tag, tag-target commit, and archive-source commit in `extra.ran-booster-core-certification` identify the immutable beta.31 Core release used for released-host qualification. It is not a second runtime version gate: if Core keeps the same published Provider API 14 / Add-on API 17 generation, the add-on must rely on that public compatibility contract rather than infer a private minimum version from unchanged interfaces.
- Provider API `14` supplies no logging capability. Diagnostics return only bounded typed results; do not add an add-on-to-Core logging channel or local fallback.
- Bitbucket does not claim the optional webhook fitness or management capabilities. Keep its existing provider behavior unchanged until a separately reviewed provider implementation proves those operations.
- On a missing or incompatible Core, register no provider, make no remote calls, and render only the safe administrator compatibility notice.
- The add-on may intentionally use public RAN Booster runtime classes through Core's shipped production autoloader after the runtime API guard. Do not vendor, copy, or ship RAN Booster production or test code.
- Do not read Core sidecar paths, persist credentials, assume deployment authority, or reach into Core container/storage internals. Provider credentials arrive only through `ProviderCredentialStore`; authenticated delivery diagnostics use only the provider-bound `AuthenticatedWebhookDeliveryEvidenceReader` supplied by Provider API 14 registration.
- The add-on owns one non-interactive guide at
  `ran_booster_documentation_sections_after_provider_bb`. It receives only the
  canonical documentation URL and administration scope, imports no Core
  documentation classes, and must not add forms, nonces, remote calls, assets,
  mutations, or service acquisition to that callback.

## Development and release

- Released certification tests require the exact sibling Core checkout recorded in `extra.ran-booster-core-certification`. Set `RAN_BOOSTER_CORE_PATH` only when that checkout lives elsewhere.
- During the earlier API 14 migration, source qualification explicitly set `RAN_BOOSTER_CORE_TEST_MODE=candidate` and verifies the exact source SHA in `extra.ran-booster-core-candidate`. This test-only record is not a release marker or certification; no tag, archive or installed-release proof is inferred. Normal Quality now uses the beta.31 certification record and default released mode; candidate mode remains an explicit test-only facility. No candidate-to-release fallback is permitted.
- Bitbucket tests consume Core's shipped `autoload.php` and public production contracts. Install only the exact certified Core checkout's locked production Composer dependencies with `composer install --no-dev`; do not install Core development dependencies or import Core-owned test fixtures.
- Run `composer install` and `composer check` for the Core-independent source-quality contract, including maintained-PHP coverage drift and its negative controls. PHPCS/PHPCBF cover product, tests and CLI PHP through the same profile; PHPStan keeps product analysis at level 8 and directly analyzes maintained development PHP at level 5 in a separate symbol environment. Owned inherited methods and locals are enforced, and blanket suppressions are rejected by the token guard. Preserve diagnostic-specific foreign/CLI exceptions and their explanations. With the exact certified Core checkout available through `RAN_BOOSTER_CORE_PATH` and its generated production dependency autoloader identified by `RAN_BOOSTER_CORE_VENDOR_AUTOLOAD`, run `composer check:host` for blocking level-8 analysis, Core-backed unit tests and the candidate contract; `composer analyze` runs the same required PHPStan gate separately and `composer build:release` remains the release-archive command. The add-on owns all PHP tooling in its own `vendor/`; Core is a production-contract fixture, not a packaged or development dependency.
- Releases are immutable GitHub artifacts. Release Please owns version/changelog/release-PR/tag/draft-release lifecycle and the pinned shared Profile B workflow owns exact tested-asset promotion. Do not add repository-local candidate markers, version engines, publisher state machines, mutable recovery, WordPress.org/SVN publishing, or ship `vendor/`, tests, Git metadata, caches, credentials, or Core files.
- Keep the entry header, `readme.txt` stable tag, Release Please manifest, and changelog aligned. Quality must emit the exact ZIP, checksum and `ran-profile-b-promotion.json` consumed by shared Profile B.
- Follow `RELEASE.md` for the authoritative release procedure, package
  automation and required evidence.

## External AI agent prohibition

Do not invoke, delegate work to, tag, enable, or otherwise use Blacksmith [code]smith,
`@codesmith-bot`, Blacksmith Autofix, Blacksmith CI Tuning, Blacksmith Testbox agents,
or any other Blacksmith AI/agent feature.

Blacksmith may be used only as infrastructure for ordinary GitHub Actions runners where
the repository workflow explicitly specifies a Blacksmith runner.

Do not click or trigger "Enable autofix", do not ask [code]smith to investigate or repair
CI, and do not call Blacksmith agent/MCP/CLI/API features that perform AI inference.

If CI fails, inspect GitHub Actions logs directly and diagnose/fix the failure yourself.

This prohibition is a cost-control requirement and must not be overridden by convenience,
CI failure, review comments, or suggestions from GitHub/Blacksmith UI.

The development profile starts at the repository root and excludes only the
production paths already covered at level 8 and dependency/generated roles.
The existing effective-coverage guard verifies the exact union, level floors,
and CLI stub filtering; no baseline or broad ignore list is accepted. The
existing HTTP `.stub` is an analysis-only external transport contract, directly
analyzed at level 5 as well as processed by production PHPStan stub validation; the WP-CLI stand-in is ordinary directly analyzed PHP.
New production paths retain the existing include-or-fail guard. New tests/scripts
are selected automatically. Fixture symbols never enter the product profile.

Locked PHPStan internal discovery calls retain occurrence-specific API-policy
annotations, with an adjacent-call negative control. The executable JSON type
assertion retains its exact diagnostic annotation and an adjacent assertion
control. These narrow annotations require reviewed #128 disposition; analysis
coverage alone does not establish complete exception acceptance.

The webhook normalizer's generic SecretsFile test double now supplies stable
string profile IDs, matching the certified Core return contract. Its test-only
adapter observes only empty/nonempty results. A direct value/order assertion and
the existing webhook success/failure cases preserve the fixture exercise.

PHPCS/PHPCBF and the suppression inventory include the existing HTTP `.stub`
contract too. Its sole prefix exception is the exact `wp_remote_get` declaration,
whose foreign identity is required by PHPStan. The adjacent-declaration and broad
annotation controls protect that boundary. All 50 maintained PHP-bearing files
are now selected by both analysis and standards; accepted exception disposition
remains separate from mechanical gate success.

The persistent standalone-variable diagnostic exemption is restricted to the
seven existing bootstrap, installed-site and CLI files enumerated by the guard.
New files must comply or receive a deliberate reviewed scope change. Regression
controls reject a future file using the same directive and verify an unrelated
function remains checked immediately inside an existing exempt file. Product
source retains only the occurrence-local base64 encoding exception needed for
Bitbucket HTTP Basic credentials; it grants no naming exemption.
