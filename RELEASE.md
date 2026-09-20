# Release process

RAN Booster Bitbucket is released as a verified GitHub artifact, never through WordPress.org or SVN.

The Quality job checks out the public `RocketsAreNostalgic/ran-booster`
repository at the exact tag-backed commit recorded in
`extra.ran-booster-core-certification`. Bitbucket tests load only Core's shipped
production `autoload.php` and public contracts; they do not reconstruct Core's
Composer development graph or import Core-owned test fixtures.

1. Review the Release Please pull request, including the header, `readme.txt`, manifest and changelog versions.
2. On the resulting release commit, run `composer install --no-dev --no-interaction` only if production Composer dependencies are introduced. The current add-on intentionally ships none.
3. Wait for the coordinated Core Release Please pull request to land, then update `extra.ran-booster-core-certification` in `composer.json` to its exact released `v...` tag and full commit. This tuple is the sole machine-readable Core certification source consumed by tests and workflows. The current certification target is RAN Booster `v1.0.0-beta.29`: tag target `ffc11fc8e40618624a785b7fca5193029c6d492e` and archive source `ff35be100a9f5c6cd77a84bcfc3734227106b0b8`. Beta.29 publishes Provider API 11 / Add-on API 16. The tag-target commit certifies the released Core checkout; the archive-source commit certifies the promoted immutable artifact's `ran-booster-release.json` marker.
4. The `GITHUB_TOKEN` pull-request Quality run is created but requires approval. Wait instead for the release workflow's automatically runnable dispatch for the exact Release Please head. `actions/checkout` uses `GITHUB_TOKEN` for its transient candidate checkout without persisting credentials; later exact candidate ref fetches receive the same token only through per-command credential helpers. It validates the bot-owned pull request and its four generated files, builds from that head, installs the ZIP without activating its Core dependency, and reads back the installed version, headers, and bytes. Manual dispatch is not release evidence. The contract test must confirm Provider API 11, Add-on API 16, the three-argument credential-bearing registration contract including `ProviderRegistrationContext`, absence of the retired Logging API and optional release/webhook-management facets, and `ran_booster_documentation_sections_after_provider_bb`.
5. Run `composer check` for the Core-independent source-quality contract. Then check out the exact certified Core commit locally, set `RAN_BOOSTER_CORE_PATH` to it, and run `composer check:repository` plus the PHPStan pilot from the Bitbucket repository. Do not run `composer install` in Core for Bitbucket's benefit. Then run `composer build:release -- "$(git rev-parse HEAD)"`.
6. Inspect `build/ran-booster-bitbucket-<version>.zip` and its SHA-256 file. The verifier enforces one `ran-booster-bitbucket/` root, the exact allowlist, matching versions, PHP syntax, the native Bitbucket documentation hook and guide, and the absence of tests, vendor, Core code, caches, credentials, and development tooling.
7. Install the ZIP beside the exact certified Core release artifact in a clean WordPress instance. Verify the Core archive digest and its `ran-booster-release.json` provenance marker against the certification tuple. Confirm the Bitbucket provider callback conforms to Core's public provider registry, its tab appears after GitHub, its complete guide appears after Core's Bitbucket provider guide, provider-bound authenticated delivery evidence produces `bb.webhook.delivery_verified`, and deactivation removes the add-on contributions by stopping add-on loading.
8. Merge the Release Please pull request. Main Quality proves the exact merged pull request and tested tree, admits the successful bot candidate, and re-uploads its ZIP, checksum, and provenance without depending on merge-commit parents. Missing or unverifiable evidence runs the complete Core-backed fallback. The release workflow still requires the successful bot candidate, never rebuilds, and targets the accepted main commit. Before publication, it selects the unique draft for that tag and commit, downloads the expected assets by their exact IDs, and compares their bytes with the admitted Quality artifact. Do not distribute GitHub's generated source archives as the plugin package.

## Release promotion boundary

RAN Booster Bitbucket uses successful exact-main qualification as its release
promotion boundary. Pull requests remain mandatory on `main`; the live ruleset
requires strict `Runtime archive`, `Quality`, and `Release candidate install
readback` checks, requires review-thread resolution, and has no bypass actors.

The current single-maintainer model does not claim a separate human authorization
principal. Exact-head automated review such as Copilot or Codex may be used as a
pre-merge review gate/process, but it is not release evidence and is not treated
as an independent human authorization boundary.

A merge does not itself authorize privileged release mutation. Quality must
successfully qualify that exact merged `main` revision. Changes to release
control and ordinary evidence inputs force a fresh main Runtime archive and
Quality run rather than reusing pull-request evidence.

The ordinary evidence inputs currently covered by Quality's fresh-evidence
classifier are:

- `composer.json`; and
- `composer.lock`.

The release-control/release-execution paths currently covered by that classifier
are:

- `.github/workflows/quality.yml`;
- `.github/workflows/release-please.yml`;
- `scripts/build-release.sh`;
- `scripts/verify-release.sh`;
- `scripts/verify-release.php`;
- `scripts/validate-release-candidate.sh`;
- `scripts/reconcile-release-candidate-marker.sh`;
- `scripts/verify-release-tag-target.sh`;
- `scripts/has-trusted-release-candidate-run.sh`; and
- `scripts/verify-immutable-release-assets.sh`.

`Quality` is the executable authority for whether a changed path invalidates
pull-request evidence. `tests/Workflow/release-state-contracts.sh` keeps this
human-readable inventory aligned with that classifier. Do not add a second
independent path catalogue to Release Please.

Release Please admits only a successful push-triggered run of the canonical
`.github/workflows/quality.yml` on `main` for this repository. The workflow
checks out that exact Quality commit and proves the merged pull-request
lifecycle before deciding what kind of reconciliation is permitted.

For an ordinary merged change, a Quality lifecycle that observes it is already
stale during release-state classification does not open or update the Release
Please proposal. This is best-effort stale suppression, not an atomic ownership
lease on `main`: if `main` advances after classification but before the Release
Please action mutates the proposal, the older run may still reconcile that
proposal. Such proposal mutation is not publication evidence and cannot by
itself publish a release; the newer `main` lifecycle will subsequently reconcile
the proposal from its own qualified state.

A merged Release Please candidate is different: after its exact candidate,
artifact, tag target and publication identity have been qualified, a later
ordinary `main` commit does not invalidate that candidate. It may complete
publication against its exact accepted commit while all provenance and immutable
readback checks remain bound to that candidate. This is deliberate exact-candidate
semantics, not a claim that a one-time branch read provides an atomic lease on
`main`.

There is no mandatory second ordinary pull request after a release-control
change. Fresh exact-main qualification admits release reconciliation, while an
already-qualified exact release candidate remains publishable under its own
identity contract.

Exact candidate identity, Core certification, artifact provenance, immutable
publication, and post-publication readback remain unchanged.

## Disposable installed proof

`tests/WordPress/bitbucket-installed-proof.sh` is the repeatable installed boundary for step 7. Point it only at an explicitly marked disposable WordPress site and provide the exact Core and Bitbucket archives, their SHA-256 digests, the full Bitbucket source commit, and the Bitbucket version. The driver reads the required Core tag, tag-target commit, and archive-source commit directly from this repository's `extra.ran-booster-core-certification`; optional `RAN_BOOSTER_CORE_TAG` / `RAN_BOOSTER_CORE_COMMIT` inputs are accepted only when they exactly match that canonical tuple. It verifies both archives before installation and verifies the Core archive's own release-provenance marker; no Core source checkout or Core-owned test fixture is accepted as proof. It confirms the installed Bitbucket tree exactly matches the retained ZIP, activates the dependency pair normally, exercises both stored plugin load orders, validates the public provider-registration contract and authenticated-delivery diagnostic, and runs missing/incompatible-Core inertness probes.

The `Certified Core installed proof` workflow performs this boundary independently of Quality. It downloads the immutable Core GitHub release named by the certification record, validates the release target, asset digest and checksum, installs WordPress 7.0.3, then runs the same disposable proof against the exact add-on source under review.

The controlled provider operation uses the durable public fixture identity
`rocketsarenostalgic/ran-booster-fixture-public-plugin` but intercepts the HTTP
request with a deterministic WordPress transport response. It needs no live
Bitbucket credential and does not read the site's saved credential store. The
driver backs up and restores the original Core directory and `active_plugins`
option, removes the installed Bitbucket candidate, and fails if cleanup cannot be proved.