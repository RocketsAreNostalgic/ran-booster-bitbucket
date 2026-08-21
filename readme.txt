=== RAN Booster Bitbucket Cloud ===
Contributors: rocketsarenostalgic
Tags: git, deploy, deployment, bitbucket
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
<!-- x-release-please-start-version -->
Stable tag: 0.1.0-beta.12
<!-- x-release-please-end -->
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bitbucket Cloud provider and operational guide for the coordinated RAN Booster Add-on API 16 generation.

== Description ==

This add-on restores the familiar Bitbucket Cloud tab in a compatible RAN
Booster installation. It supports public and private repository discovery,
API-token credential validation, archive preparation, webhook handling and
diagnostics. Its add-on-owned Documentation guide covers API-token permissions,
package connection, manual webhook setup, Transporter recovery, deactivation
and support.

This free official add-on is distributed as a verified GitHub release artifact,
not through WordPress.org. It also serves as the production-shaped reference for
maintained provider extensions. It performs no provider registration or remote calls when RAN
Booster Provider API 10 or Add-on API 16 is absent or incompatible. The optional
webhook fitness and management capabilities are not yet implemented; use the
included manual Bitbucket webhook guidance.

== Installation ==

1. Install and activate a compatible RAN Booster release.
2. Download this add-on's ZIP and checksum from the same GitHub release. Do not
   use GitHub's generated source archives.
3. Verify the checksum, then upload and activate the ZIP through WordPress.
4. Open RAN Booster. The Bitbucket tab appears directly after GitHub.
5. Open RAN Booster Documentation for the complete Bitbucket operational guide.

Transporter copies only explicitly selected eligible file-stored credentials.
Each carried credential requires an explicit import, saved-target or leave
choice on a target where this compatible add-on is active; there is no fallback
and no token permission assurance. Copying does not revoke or rotate the source
API token.

== Releases, updates, and support ==

Install updates from the repository's GitHub Releases page using the ZIP and
checksum attached to the same release. This add-on is not distributed through
WordPress.org and does not currently register an automatic update service.

Use the GitHub issue tracker for ordinary support. Follow the repository's
security policy for vulnerabilities. Never post credentials, webhook secrets,
signed URLs, release assets, customer data or vulnerability details in a
public issue.

== Changelog ==

= 0.1.0-alpha.1 =
* Initial standalone Bitbucket Cloud provider release.
