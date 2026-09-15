# Release process

RAN Booster Bitbucket is released as a verified GitHub artifact, never through WordPress.org or SVN.

The Quality job checks out the public `RocketsAreNostalgic/ran-booster`
repository at the exact tag-backed commit recorded in
`extra.ran-booster-core-certification`. Bitbucket tests load only Core's shipped
production `autoload.php` and public contracts; they do not reconstruct Core's
Composer development graph or import Core-owned test fixtures.

1. Review the Release Please pull request, including the header, `readme.txt`, manifest and changelog versions.
2. On the resulting release commit, run `composer install --no-dev --no-interaction` only if production Composer dependencies are introduced. The current add-on intentionally ships none.
3. Wait for the coordinated Core Release Please pull request to land, then update `extra.ran-booster-core-certification` in `composer.json` to its exact released `v...` tag and full commit. This tuple is the sole machine-readable certification source consumed by tests and workflows. The current boundary is RAN Booster `v1.0.0-beta.28` at `b634c431951ac75bd7022946ead26cbac949940b`. Provider API 10 / Add-on API 16 alone are not sufficient evidence because older Core releases used the same tuple; the runtime compatibility check also requires the current provider-bound authenticated webhook-delivery evidence contract.
4. The `GITHUB_TOKEN` pull-request Quality run is created but requires approval. Wait instead for the release workflow's automatically runnable dispatch for the exact Release Please head. `actions/checkout` uses `GITHUB_TOKEN` for its transient candidate checkout without persisting credentials; later exact candidate ref fetches receive the same token only through per-command credential helpers. It validates the bot-owned pull request and its four generated files, builds from that head, installs the ZIP without activating its Core dependency, and reads back the installed version, headers, and bytes. Manual dispatch is not release evidence. The contract test must confirm Provider API 10, Add-on API 16, the current provider surface, absence of the retired Logging API and optional release/webhook-management facets, and `ran_booster_documentation_sections_after_provider_bb`.
5. Check out the exact certified Core commit locally, set `RAN_BOOSTER_CORE_PATH` to it, and run `composer check` and the PHPStan pilot from the Bitbucket repository. Do not run `composer install` in Core for Bitbucket's benefit. Then run `composer build:release -- "$(git rev-parse HEAD)"`.
6. Inspect `build/ran-booster-bitbucket-<version>.zip` and its SHA-256 file. The verifier enforces one `ran-booster-bitbucket/` root, the exact allowlist, matching versions, PHP syntax, the native Bitbucket documentation hook and guide, and the absence of tests, vendor, Core code, caches, credentials, and development tooling.
7. Install the ZIP beside the exact certified Core release artifact in a clean WordPress instance. Verify the Core archive digest and its `ran-booster-release.json` provenance marker against the certification tuple. Confirm the Bitbucket provider callback conforms to Core's public provider registry, its tab appears after GitHub, its complete guide appears after Core's Bitbucket provider guide, authenticated delivery evidence is accepted by diagnostics, and deactivation removes the add-on contributions by stopping add-on loading.
8. Merge the Release Please pull request. Main Quality proves the exact merged pull request and tested tree, admits the successful bot candidate, and re-uploads its ZIP, checksum, and provenance without depending on merge-commit parents. Missing or unverifiable evidence runs the complete Core-backed fallback. The release workflow still requires the successful bot candidate, never rebuilds, and targets the accepted main commit. Before publication, it selects the unique draft for that tag and commit, downloads the expected assets by their exact IDs, and compares their bytes with the admitted Quality artifact. Do not distribute GitHub's generated source archives as the plugin package.

## Disposable installed proof

`tests/WordPress/bitbucket-installed-proof.sh` is the repeatable installed boundary for step 7. Point it only at an explicitly marked disposable WordPress site and provide the exact Core and Bitbucket archives, their SHA-256 digests, the full Bitbucket source commit, and the Bitbucket version. The driver reads the required Core tag/commit directly from this repository's `extra.ran-booster-core-certification`; optional `RAN_BOOSTER_CORE_TAG` / `RAN_BOOSTER_CORE_COMMIT` inputs are accepted only when they exactly match that canonical tuple. It verifies both archives before installation and verifies the Core archive's own release-provenance marker; no Core source checkout or Core-owned test fixture is accepted as proof. It confirms the installed Bitbucket tree exactly matches the retained ZIP, activates the dependency pair normally, exercises both stored plugin load orders, validates the public provider-registration contract and runs missing/incompatible-Core inertness probes.

The `Certified Core installed proof` workflow performs this boundary independently of Quality. It downloads the immutable Core GitHub release named by the certification record, validates the release target, asset digest and checksum, installs WordPress 7.0.3, then runs the same disposable proof against the exact add-on source under review.

The controlled provider operation uses the durable public fixture identity
`rocketsarenostalgic/ran-booster-fixture-public-plugin` but intercepts the HTTP
request with a deterministic WordPress transport response. It needs no live
Bitbucket credential and does not read the site's saved credential store. The
driver backs up and restores the original Core directory and `active_plugins`
option, removes the installed Bitbucket candidate, and fails if cleanup cannot
be proved.
