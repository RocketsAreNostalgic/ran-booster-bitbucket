# RAN Booster Bitbucket add-on

This repository is a dependent RAN Booster add-on, not a standalone plugin.

## Runtime contract

- RAN Booster is required. This add-on supports exactly Provider API `10` and the official-suite Add-on API `16` generation, WordPress 7.0+, and PHP 8.2+; PHP 8.4 is recommended, not required.
- Provider API `10` supplies no logging capability. Diagnostics return only bounded typed results; do not add an add-on-to-Core logging channel or local fallback.
- Bitbucket does not yet claim the optional webhook fitness or management capabilities. Keep its existing provider behavior unchanged until a separately reviewed provider implementation proves those operations.
- On a missing or incompatible Core, register no provider, make no remote calls, and render only the safe administrator compatibility notice.
- The add-on may intentionally use public RAN Booster runtime classes and the installed Core vendor autoloader after that exact guard. Do not vendor, copy, or ship RAN Booster code.
- Do not read Core sidecar paths, persist credentials, assume deployment authority, or reach into Core container/storage internals. Provider credentials arrive only through `ProviderCredentialStore`.
- The add-on owns one non-interactive guide at
  `ran_booster_documentation_sections_after_provider_bb`. It receives only the
  canonical documentation URL and administration scope, imports no Core
  documentation classes, and must not add forms, nonces, remote calls, assets,
  mutations, or service acquisition to that callback.

## Development and release

- Tests require a compatible sibling `../ran-booster` checkout with its Composer dependencies. Set `RAN_BOOSTER_CORE_PATH` only when it lives elsewhere.
- Run `composer install`, `composer check`, and `composer build:release`. The add-on owns PHP tooling in its own `vendor/`; the sibling Core is a contract fixture, not a packaged dependency.
- Releases are private GitHub artifacts. Do not add WordPress.org/SVN publishing or ship `vendor/`, tests, Git metadata, caches, credentials, or Core files. The verified archive must contain the add-on-owned Bitbucket guide and exact native documentation hook.
- Keep the entry header, `readme.txt` stable tag, Release Please manifest, and changelog aligned.
- Follow `RELEASE.md` for the authoritative release procedure, package
  automation and required evidence.
