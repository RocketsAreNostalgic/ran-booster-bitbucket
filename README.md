# RAN Booster Bitbucket Cloud

RAN Booster Bitbucket Cloud is a premium add-on for RAN Booster. It restores
the Bitbucket Cloud provider and its settings tab through the exact RAN Booster
Provider API 7 contract. It requires the coordinated Add-on API 13 generation
and contributes its complete operational guide directly after
Core's Bitbucket provider documentation.

## Compatibility and safety

- Requires WordPress 7.0+, PHP 8.2+ (PHP 8.4 recommended), and compatible RAN Booster Provider API 7 and Add-on API 13.
- Provider API 7 supplies no logging capability. Provider diagnostics return bounded typed results to Core and never send exceptions or vendor text through a logging facade.
- Without compatible Booster, it makes no remote calls or provider registrations and shows administrators a safe compatibility notice.
- It receives credential data only through Booster's provider-scoped reader. It neither stores credentials nor reads Booster's sidecar paths, Core container, or Core storage.
- The provider intentionally does not implement the optional `ReleaseCatalog` capability.
- Its documentation callback is non-interactive. It receives no Core object or facade and performs no remote work.

## Install and operate

1. Install and activate compatible RAN Booster.
2. Install and activate the private Bitbucket release ZIP.
3. Open RAN Booster; the Bitbucket tab follows GitHub.
4. Open RAN Booster's Documentation tab for the add-on-owned guide to API-token
   permissions, package connection, manual webhook setup, Transporter recovery,
   deactivation and private support.

Deactivating the add-on stops it from registering the `bb` provider. It owns no
installation records or credential storage to remove.

## Development

This is a dependent add-on, not a generic standalone plugin. Development tests
need a compatible sibling `../ran-booster` checkout with Composer dependencies:

```sh
composer install
composer check
composer build:release
```

Set `RAN_BOOSTER_CORE_PATH` if the Core checkout is elsewhere. The add-on owns
its PHP tools in its local `vendor/`, but never packages a vendor tree or Core
code in the release artifact.

## Releases and support

Releases are private GitHub artifacts, not WordPress.org/SVN publications. See
[RELEASE.md](RELEASE.md) and use Conventional Commits as described in
[CONTRIBUTING.md](CONTRIBUTING.md). Report issues through the repository's
GitHub issue tracker, but never include credentials, webhook secrets, signed
URLs, private release assets or customer data. Use the confidential contact
supplied with the private release for sensitive security reports.

## Licence

GPL-2.0-or-later. See [license.txt](license.txt) and [NOTICE.md](NOTICE.md).
