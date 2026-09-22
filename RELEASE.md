# Release process

RAN Booster Bitbucket is released as an immutable verified GitHub artifact, never through WordPress.org or SVN.

## Architecture

Release Please owns version semantics, changelog, release PR, tag and draft GitHub Release creation. The pinned shared RAN Profile B workflow owns exact successful-main admission, bounded exact Release Please candidate Quality dispatch, exact tested-asset promotion and immutable publication.

The repository owns only its product-specific evidence:

- `Quality` builds and verifies the Bitbucket ZIP and checksum from the exact revision under test;
- the promotion manifest binds those exact bytes to the Quality revision and expected release tag;
- repository Quality retains the exact certified Core contract and source tests;
- the release-candidate lane installs and reads back the exact candidate ZIP;
- `Certified Core installed proof` independently exercises the add-on beside the immutable certified Core release in WordPress 7.0.3.

Do not recreate local Release Please lifecycle state, candidate markers, custom version semantics, merge geometry, trusted-run discovery, mutable recovery, or a repository-local publisher.

## Contributor release checks

1. Review the Release Please pull request, including the plugin header, `readme.txt`, manifest and changelog versions.
2. Run `composer check` for the Core-independent source-quality contract.
3. Check out the exact Core release recorded in `extra.ran-booster-core-certification`, install its locked production dependencies, set `RAN_BOOSTER_CORE_PATH` and `RAN_BOOSTER_CORE_VENDOR_AUTOLOAD`, and run `composer check:host`.
4. `composer analyze` remains the separate advisory PHPStan pilot; Profile B migration does not promote it to a blocking gate.
5. Run `composer build:release -- "$(git rev-parse HEAD)"`, then run `composer verify:release -- "build/ran-booster-bitbucket-<version>.zip" "$(git rev-parse HEAD)"` for the resulting ZIP. Preserve the package allowlist and exclusion of tests, vendor, Core code, caches, credentials and development tooling.
6. Preserve the certified Core tuple in `composer.json`: exact immutable release tag, tag-target commit and archive-source commit.
7. Do not manually publish, replace or clobber release assets. A bad artifact is corrected by a new qualified version.

## Automated release path

```text
ordinary PR / main
→ full Quality
→ exact tested ZIP + checksum + promotion manifest
→ shared Profile B exact successful-main admission
→ Release Please
→ exact candidate Quality when a release PR exists
→ draft release bound to the admitted main revision
→ upload/reconcile only the exact Quality assets
→ immutable publication + readback
```

`workflow_dispatch` on `Quality` is intentionally input-free. The shared Profile B workflow dispatches the canonical Release Please branch when exact candidate qualification is required; the repository validates that the dispatched revision is the unique bot-owned Release Please candidate before treating it as the release-candidate lane.

The release workflow is a thin caller pinned to the reviewed shared Profile B implementation. It does not rebuild release bytes. The shared promoter downloads the exact run/attempt artifact named by the successful main Quality run and requires the promotion manifest to bind repository, admitted SHA, tag, asset names and SHA-256 digests. Missing, conflicting or expired evidence fails closed.

Release Please is configured with `draft: true` and `force-tag-creation: true`. Publication occurs only after the exact tested assets have been attached and read back. GitHub immutable releases remain an external production constraint.

## Certified Core installed proof

`tests/WordPress/bitbucket-installed-proof.sh` remains the repeatable installed boundary. It verifies both archives, Core release provenance, the installed Bitbucket tree, normal dependency activation, public provider registration, authenticated-delivery diagnostics and cleanup.

The `Certified Core installed proof` workflow independently downloads the immutable Core GitHub release named by the certification record, validates release target, asset digest and checksum, installs WordPress 7.0.3, then runs the same disposable proof against the exact add-on source under review.
