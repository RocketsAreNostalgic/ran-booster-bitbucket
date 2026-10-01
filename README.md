# RAN Booster Bitbucket Cloud

RAN Booster Bitbucket Cloud is the free Bitbucket provider for RAN Booster. It
registers the `bb` provider and adds the Bitbucket settings tab through Provider
API 13. The add-on requires Add-on API 16 and inserts its operational guide
after Core's Bitbucket provider documentation.

## Compatibility and safety

- Requires WordPress 7.0+, PHP 8.2+ (PHP 8.4 recommended), and exactly RAN Booster Provider API 13 and Add-on API 16.
- The historical Provider API 11 certification target is RAN Booster `v1.0.0-beta.29`: tag target `ffc11fc8e40618624a785b7fca5193029c6d492e`, with the immutable release archive promoted from qualified candidate `ff35be100a9f5c6cd77a84bcfc3734227106b0b8`. That immutable record is retained for provenance; it does not certify this API 13 candidate. A matching Core release and renewed installed proof are required before release.
- `Requires Plugins: ran-booster` declares Core as a package dependency, but WordPress does not check Booster's APIs. The add-on checks `RAN_BOOSTER_PROVIDER_API_VERSION`, `RAN_BOOSTER_ADDON_API_VERSION` and the supported runtime mode; a missing or incompatible API boundary disables provider registration and remote calls.
- Provider API 13 has no logging capability. Diagnostics return bounded `ProviderDiagnosticResult` values to Core and never send exceptions or vendor text through a logging facade.
- If Core is missing or incompatible, the add-on displays a compatibility notice only to administrators who can activate plugins.
- Credentials come only from Core's provider-bound `ProviderCredentialStore`. Authenticated webhook-delivery diagnostics use Core's provider-bound `AuthenticatedWebhookDeliveryEvidenceReader`, supplied by Provider API 13 registration. The add-on neither stores credentials nor reads Core's sidecar paths, service container, database repositories or test fixtures.
- The provider intentionally implements none of the optional release metadata,
  candidate-listing, inspection, acquisition, native-target or release-workflow capabilities.
- The provider does not implement the optional webhook fitness or management capabilities; use the included instructions to configure Bitbucket webhooks manually. Its webhook diagnostics can still report whether Core has authenticated a delivery and whether that delivery matched a managed package.
- The documentation callback is non-interactive. It receives no Core object or facade and makes no remote calls.

## Install and operate

1. Install and activate a RAN Booster release that provides Provider API 13 and Add-on API 16.
2. Download `ran-booster-bitbucket-<version>.zip` and its `.sha256` file from
   the same GitHub release. Do not use GitHub's generated source archives.
3. Verify the checksum, then upload and activate the ZIP through WordPress's
   Plugins screen.
4. Open RAN Booster. The Bitbucket tab appears after GitHub.
5. Open RAN Booster's Documentation tab for the add-on's guide to API-token
   permissions, package connection, manual webhook setup, Transporter recovery,
   deactivation and support.

Transporter copies only eligible file-stored credentials selected for export.
On a target with a compatible version of this add-on active, each carried
credential requires a separate choice: import the copied credential, use a
saved Bitbucket credential, or leave its packages unchanged. There is no
credential or anonymous fallback, and Transporter does not assess token
permissions. Copying does not revoke or rotate the source API token.

Deactivating the add-on stops it from registering the `bb` provider. It owns no
installation records or credential storage to remove.

## Development

This is a dependent add-on, not a generic standalone plugin. Development tests
use the exact API 13 candidate in `extra.ran-booster-core-candidate.commit`
(currently `a9fd5491b69e71a8543c507d097d1b1028bfa196`) as their contract fixture.
Check out that commit in a separate Core checkout at `../ran-booster`. The suite
loads Core's shipped `autoload.php` and public production contracts, which require
that checkout's locked production Composer dependencies. Do not install Core
development dependencies. Candidate mode is explicit and verifies the checkout's
exact commit; it does not certify a Core release. From this add-on checkout:

```sh
composer install
composer check
(cd ../ran-booster && composer install --no-dev --no-interaction --prefer-dist --no-progress)
export RAN_BOOSTER_CORE_TEST_MODE=candidate
export RAN_BOOSTER_CORE_PATH=../ran-booster
export RAN_BOOSTER_CORE_VENDOR_AUTOLOAD=../ran-booster/vendor/autoload.php
composer check:host
composer analyze
composer build:release -- "$(git rev-parse HEAD)"
```

`composer check` is the Core-independent source-quality contract used by the
shared PHP provider. It also checks that maintained product PHP remains directly
selected by PHPStan and PHPCS/PHPCBF, including future root files.
`composer check:host` adds blocking level-8 PHPStan analysis,
the Core-backed unit and release-candidate contracts and is required for the
source handoff when the exact candidate Core is available. It does not replace
release certification or installed proof.

Set `RAN_BOOSTER_CORE_PATH` if the exact candidate Core checkout is elsewhere and set `RAN_BOOSTER_CORE_VENDOR_AUTOLOAD` to that checkout's generated `vendor/autoload.php` when running Core-backed source tests or analysis.
The default test mode uses the historical `ran-booster-core-certification` tuple;
that API 11 release cannot qualify this API 13 branch. Keep candidate mode enabled
for both host checks and focused analysis. Follow [RELEASE.md](RELEASE.md) for the
separate matching-release and installed-proof requirements before merge/publication.
Do not import Core-owned test fixtures into this repository. The add-on owns its
PHP tools in its local `vendor/`, but never packages a vendor tree or Core code
in the release artifact. PHPStan is a blocking, Bitbucket-only host gate; read
the decision record in [CONTRIBUTING.md](CONTRIBUTING.md) before raising its
level, adding suppressions or adopting it elsewhere.

## Releases and support

Releases are GitHub artifacts built and checked by the repository's release
workflow, not WordPress.org/SVN publications. To upgrade, download the ZIP and
checksum attached to the same release. The plugin's `Update URI` prevents
WordPress.org from claiming its update channel; this add-on does not currently
register an automatic update service.

See [RELEASE.md](RELEASE.md) and use Conventional Commits as described in
[CONTRIBUTING.md](CONTRIBUTING.md). Report ordinary issues through the
repository's GitHub issue tracker. Report vulnerabilities through the
[security policy](SECURITY.md), and never put credentials, webhook secrets,
signed URLs, release assets, or customer data in a public issue.

## License

GPL-2.0-or-later. See [license.txt](license.txt) and [NOTICE.md](NOTICE.md).
