# Private release procedure

RAN Booster Bitbucket is released as a private GitHub artifact, never through WordPress.org or SVN.

1. Review the Release Please pull request, including the header, `readme.txt`, `composer.json`, manifest and changelog versions.
2. On the resulting release commit, run `composer install --no-dev --no-interaction` only if production Composer dependencies are introduced. The current add-on intentionally ships none.
3. Run `composer check` with a compatible sibling RAN Booster checkout, then `composer build:release`.
4. Inspect `build/ran-booster-bitbucket-<version>.zip` and its SHA-256 file. The verifier enforces one `ran-booster-bitbucket/` root, the exact allowlist, matching versions, PHP syntax, and the absence of tests, vendor, Core code, caches, credentials, and development tooling.
5. Install the ZIP beside compatible RAN Booster in a clean WordPress instance. Confirm the Bitbucket provider registers, its tab/documentation appears, and deactivation removes the provider by stopping add-on loading.
6. Attach the verified ZIP and checksum to the private GitHub release after repository ownership, Actions access, and any required read token are provisioned.
