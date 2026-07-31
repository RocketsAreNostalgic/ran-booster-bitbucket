# Private release procedure

RAN Booster Bitbucket is released as a private GitHub artifact, never through WordPress.org or SVN.

The repository's Quality and release-package jobs require an Actions repository
secret named `RAN_BOOSTER_CORE_READ_SSH_KEY`. Store the private half of a
dedicated SSH deploy-key pair in that secret, then add its public half to the
private `RocketsAreNostalgic/ran-booster` repository as a read-only deploy key.
Do not enable write access. The add-on repository's `GITHUB_TOKEN` cannot read
that sibling private repository and is intentionally not used as a fallback.

1. Review the Release Please pull request, including the header, `readme.txt`, manifest and changelog versions.
2. On the resulting release commit, run `composer install --no-dev --no-interaction` only if production Composer dependencies are introduced. The current add-on intentionally ships none.
3. Wait for the coordinated Core Release Please pull request to land and record its exact released `v...` tag in the Bitbucket release review. Older Add-on API generations are unsupported.
4. Dispatch the Quality workflow on the Release Please pull-request branch with `core-ref` set to that exact Core tag (`gh workflow run quality.yml --ref <release-please-branch> -f core-ref=<core-tag>`). This explicit dispatch is required because a pull request maintained with `GITHUB_TOKEN` does not trigger another Actions workflow. Do not publish from a development-only run against Core `main`. The contract test must confirm Provider API 6, Logging API 1, Add-on API 12 and `ran_booster_documentation_sections_after_provider_bb`.
5. Check out that same Core tag locally, set `RAN_BOOSTER_CORE_PATH` to it, run `composer check`, then run `composer build:release`.
6. Inspect `build/ran-booster-bitbucket-<version>.zip` and its SHA-256 file. The verifier enforces one `ran-booster-bitbucket/` root, the exact allowlist, matching versions, PHP syntax, the native Bitbucket documentation hook and guide, and the absence of tests, vendor, Core code, caches, credentials, and development tooling.
7. Install the ZIP beside the recorded Core release in a clean WordPress instance. Confirm the Bitbucket provider registers, its tab appears after GitHub, its complete guide appears after Core's Bitbucket provider guide, and deactivation removes both contributions by stopping add-on loading.
8. Merge the Release Please pull request. In the same workflow run, the package-release job checks out the exact created tag and the pinned compatible Core release, repeats the checks and build, confirms the tag, package version and prerelease state, and attaches the verified ZIP and checksum to the private GitHub release. Do not distribute GitHub's generated source archives as the plugin package.
