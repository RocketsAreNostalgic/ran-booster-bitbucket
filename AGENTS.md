# RAN Booster Bitbucket add-on

This repository is a dependent RAN Booster add-on, not a standalone plugin.

## Runtime contract

- RAN Booster is required. This add-on supports exactly Provider API `5` and Logging API `1`, WordPress 7.0+, and PHP 8.3+; PHP 8.4 is recommended, not required.
- On a missing or incompatible Core, register no provider, make no remote calls, and render only the safe administrator compatibility notice.
- The add-on may intentionally use public RAN Booster runtime classes and the installed Core vendor autoloader after that exact guard. Do not vendor, copy, or ship RAN Booster code.
- Do not read Core sidecar paths, persist credentials, assume deployment authority, or reach into Core container/storage internals. Provider credentials arrive only through `ProviderCredentialStore`.

## Development and release

- Tests require a compatible sibling `../ran-booster` checkout with its Composer dependencies. Set `RAN_BOOSTER_CORE_PATH` only when it lives elsewhere.
- Run `composer install`, `composer check`, and `composer build:release`. The add-on owns PHP tooling in its own `vendor/`; the sibling Core is a contract fixture, not a packaged dependency.
- Releases are private GitHub artifacts. Do not add WordPress.org/SVN publishing or ship `vendor/`, tests, Git metadata, caches, credentials, or Core files.
- Keep the entry header, `readme.txt` stable tag, `composer.json` version, Release Please manifest, and changelog aligned.
