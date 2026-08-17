# Private release procedure

RAN Booster Bitbucket is released as a verified GitHub artifact, never through WordPress.org or SVN.

The repository's Quality jobs require an Actions repository
secret named `RAN_BOOSTER_CORE_READ_SSH_KEY`. Store the private half of a
dedicated SSH deploy-key pair in that secret, then add its public half to the
private `RocketsAreNostalgic/ran-booster` repository as a read-only deploy key.
Do not enable write access. The add-on repository's `GITHUB_TOKEN` cannot read
that sibling private repository and is intentionally not used as a fallback.

1. Review the Release Please pull request, including the header, `readme.txt`, manifest and changelog versions.
2. On the resulting release commit, run `composer install --no-dev --no-interaction` only if production Composer dependencies are introduced. The current add-on intentionally ships none.
3. Wait for the coordinated Core Release Please pull request to land, then update `extra.ran-booster-core-certification` in `composer.json` to its exact released `v...` tag and full commit. This tuple is the sole machine-readable certification source consumed by tests and workflows. Older Add-on API generations are unsupported.
4. The `GITHUB_TOKEN` pull-request Quality run is created but requires approval. Wait instead for the release workflow's automatically runnable dispatch for the exact Release Please head. It validates the bot-owned pull request and its four generated files, builds from that head, installs the ZIP without activating its Core dependency, and reads back the installed version, headers, and bytes. Manual dispatch is not release evidence.
5. Check out that same certified Core commit locally, set `RAN_BOOSTER_CORE_PATH` to it, run `composer check`, then run `composer build:release -- "$(git rev-parse HEAD)"`.
6. Inspect `build/ran-booster-bitbucket-<version>.zip` and its SHA-256 file. The verifier enforces one `ran-booster-bitbucket/` root, the exact allowlist, matching versions, PHP syntax, the native Bitbucket documentation hook and guide, and the absence of tests, vendor, Core code, caches, credentials, and development tooling.
7. Install the ZIP beside the recorded Core release in a clean WordPress instance. Confirm the Bitbucket provider registers, its tab appears after GitHub, its complete guide appears after Core's Bitbucket provider guide, and deactivation removes both contributions by stopping add-on loading.
8. Merge the Release Please pull request. Main Quality proves the exact merged pull request and tested tree, admits the successful bot candidate, and re-uploads its ZIP, checksum, and provenance without depending on merge-commit parents. Missing or unverifiable evidence runs the complete Core-backed fallback. The release workflow still requires the successful bot candidate, never rebuilds, and targets the accepted main commit. Do not distribute GitHub's generated source archives as the plugin package.

## Disposable installed proof

`tests/WordPress/bitbucket-installed-proof.sh` is the repeatable installed boundary for step 7. Point it only at an explicitly marked disposable WordPress site and provide the exact Core/Bitbucket archives, their SHA-256 digests, the full Bitbucket source commit and the certified Core source checkout. The driver verifies both archives before installation, confirms the installed Bitbucket tree exactly matches the retained ZIP, activates the dependency pair normally, exercises both stored plugin load orders, and runs missing/incompatible-Core inertness probes.

The controlled provider operation uses the durable public fixture identity
`rocketsarenostalgic/ran-booster-fixture-public-plugin` but intercepts the HTTP
request with a deterministic WordPress transport response. It needs no live
Bitbucket credential and does not read the site's saved credential store. The
driver backs up and restores the original Core directory and `active_plugins`
option, removes the installed Bitbucket candidate, and fails if cleanup cannot
be proved.
