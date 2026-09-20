# RAN Booster Bitbucket add-on

This repository is a dependent RAN Booster add-on, not a standalone plugin.

## Runtime contract

- RAN Booster is required. This add-on supports exactly Provider API `11` and the official-suite Add-on API `16` generation, WordPress 7.0+, and PHP 8.2+; PHP 8.4 is recommended, not required.
- The exact tag/commit in `extra.ran-booster-core-certification` is the Core release against which this repository's tests and release evidence are certified. It is not a second runtime version gate: if Core keeps the same published Provider API 11 / Add-on API 16 generation, the add-on must rely on that public compatibility contract rather than infer a private minimum version from unchanged interfaces.
- Provider API `11` supplies no logging capability. Diagnostics return only bounded typed results; do not add an add-on-to-Core logging channel or local fallback.
- Bitbucket does not claim the optional webhook fitness or management capabilities. Keep its existing provider behavior unchanged until a separately reviewed provider implementation proves those operations.
- On a missing or incompatible Core, register no provider, make no remote calls, and render only the safe administrator compatibility notice.
- The add-on may intentionally use public RAN Booster runtime classes through Core's shipped production autoloader after the runtime API guard. Do not vendor, copy, or ship RAN Booster production or test code.
- Do not read Core sidecar paths, persist credentials, assume deployment authority, or reach into Core container/storage internals. Provider credentials arrive only through `ProviderCredentialStore`; authenticated delivery diagnostics use only the provider-bound `AuthenticatedWebhookDeliveryEvidenceReader` supplied by Provider API 11 registration.
- The add-on owns one non-interactive guide at
  `ran_booster_documentation_sections_after_provider_bb`. It receives only the
  canonical documentation URL and administration scope, imports no Core
  documentation classes, and must not add forms, nonces, remote calls, assets,
  mutations, or service acquisition to that callback.

## Development and release

- Tests require the exact sibling Core checkout recorded in `extra.ran-booster-core-certification`. Set `RAN_BOOSTER_CORE_PATH` only when that checkout lives elsewhere.
- Bitbucket tests consume only Core's shipped `autoload.php` and public production contracts. Do not install Core's Composer development dependencies for this repository and do not import Core-owned test fixtures.
- Run `composer install` and `composer check` for the Core-independent source-quality contract. With the exact certified Core checkout available through `RAN_BOOSTER_CORE_PATH`, run `composer check:repository` for the Core/release-state suite; `composer analyse` remains the PHPStan pilot and `composer build:release` remains the release-archive command. The add-on owns all PHP tooling in its own `vendor/`; Core is a production-contract fixture, not a packaged or development dependency.
- Releases are GitHub artifacts. Do not add WordPress.org/SVN publishing or ship `vendor/`, tests, Git metadata, caches, credentials, or Core files. The verified archive must contain the add-on-owned Bitbucket guide and exact native documentation hook.
- Keep the entry header, `readme.txt` stable tag, Release Please manifest, and changelog aligned.
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
