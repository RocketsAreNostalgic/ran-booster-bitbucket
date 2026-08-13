# RAN Booster Bitbucket Cloud

RAN Booster Bitbucket Cloud is the free official Bitbucket add-on for RAN Booster
and a production-shaped reference for maintained provider extensions. It restores
the Bitbucket Cloud provider and its settings tab through the exact RAN Booster
Provider API 9 contract. It requires the coordinated Add-on API 14 generation
and contributes its complete operational guide directly after
Core's Bitbucket provider documentation.

## Compatibility and safety

- Requires WordPress 7.0+, PHP 8.2+ (PHP 8.4 recommended), and exactly RAN Booster Provider API 9 and Add-on API 14.
- `Requires Plugins: ran-booster` tells WordPress that Core is a package dependency; it does not prove API compatibility. The exact runtime markers remain authoritative, and a mismatch keeps provider registration and remote calls inert.
- Provider API 9 supplies no logging capability. Provider diagnostics return bounded typed results to Core and never send exceptions or vendor text through a logging facade.
- Without compatible Booster, it makes no remote calls or provider registrations and shows administrators a safe compatibility notice.
- It receives credential data only through Booster's provider-scoped reader. It neither stores credentials nor reads Booster's sidecar paths, Core container, or Core storage.
- The provider intentionally does not implement the optional `ReleaseCatalog` capability.
- The provider intentionally does not yet implement the optional webhook fitness or management capabilities; manual Bitbucket webhook guidance remains the supported path.
- Its documentation callback is non-interactive. It receives no Core object or facade and performs no remote work.

## Install and operate

1. Install and activate compatible RAN Booster.
2. Install and activate the verified Bitbucket release ZIP.
3. Open RAN Booster; the Bitbucket tab follows GitHub.
4. Open RAN Booster's Documentation tab for the add-on-owned guide to API-token
   permissions, package connection, manual webhook setup, Transporter recovery,
   deactivation and support.

Transporter copies only explicitly selected eligible file-stored credentials.
Each carried credential requires an explicit import, saved-target or leave
choice on a target where this compatible add-on is active; there is no fallback
and no token permission assurance. Copying does not revoke or rotate the source
API token.

Deactivating the add-on stops it from registering the `bb` provider. It owns no
installation records or credential storage to remove.

## Development

This is a dependent add-on, not a generic standalone plugin. Development tests
need a compatible sibling `../ran-booster` checkout with Composer dependencies:

```sh
composer install
composer check
composer analyse
composer build:release -- "$(git rev-parse HEAD)"
```

Set `RAN_BOOSTER_CORE_PATH` if the Core checkout is elsewhere. The add-on owns
its PHP tools in its local `vendor/`, but never packages a vendor tree or Core
code in the release artifact. Static analysis is a non-blocking, Bitbucket-only
pilot; read the decision record in [CONTRIBUTING.md](CONTRIBUTING.md) before
raising its level, adding suppressions or adopting it elsewhere.

## Releases and support

Releases are verified GitHub artifacts, not WordPress.org/SVN publications. See
[RELEASE.md](RELEASE.md) and use Conventional Commits as described in
[CONTRIBUTING.md](CONTRIBUTING.md). Report issues through the repository's
GitHub issue tracker, but never include credentials, webhook secrets, signed
URLs, release assets or customer data. Request a confidential contact route
without disclosing details before submitting a sensitive security report.

## Licence

GPL-2.0-or-later. See [license.txt](license.txt) and [NOTICE.md](NOTICE.md).
