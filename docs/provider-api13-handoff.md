# Bitbucket Provider API 13 integration handoff

Status: implemented and locally candidate-qualified. Ben explicitly approved
branch push and successor draft PR creation after the initial automatic approval
block. Native CI, hosted review and actual-published-tuple evidence are recorded
in the successor PR conversation. No merge, release publication or released-Core
certification is claimed.

## Ownership and source identity

Ben retired all previous ancillary owners and assigned this lane directly.
Takeover is recorded in Bitbucket #89 comment 5942476111 and Core #167 comment
5942477273. Core integration, dependency composition and release sequencing stay
with the overall coordinator. UI/manual acceptance is deferred; Plugin Library
and CI optimization are outside scope. Blacksmith AI/autofix was not used.

- Branch: `quality/167-bitbucket-api13`; intended destination: linked successor draft PR to `main`.
- Base: `e3bbadca96587d07f655df89bc9eda1515c6dd20`.
- Qualified implementation commit: `fd4e720ed4ff57318f67a5c896c74a3797a912d8`.
- Qualified implementation tree: `8f17226f09fd3f2a2876584c9e6bb5d677237527`.
- This handoff document is added separately; subsequent head/tree must be recorded in the PR conversation after publication.
- Current Core candidate: `d18299fd4ad69b2df1efd319d604d1a2195b6d2d` (coordinator handoff 5942591785).
- Current Core tree: `cb1452642c64ebbfa5c069b79e53a117c044ba09`.

All three #89 commits are retained as ancestors: `073a64328b62e2a9ec253a1ad3672cd5074dd199`,
`0a4b6d5e265a7dc614f6c9d1783ab28756f97f0d`,
`70e61d21cc61ccbace9cf79e10dd43472ef77a34`.
Merge commit `706d33ae38b9c0070f6927c44bdc246dede98ac7` combines that head with
current main, retaining #90's release-candidate integrity checks. The API-12
marker/pin in #89 are intentionally superseded by API 13; its candidate-mode
and release-certification separation and documentation fixes are preserved.
#89 remains open until the successor is published and linked. Release #75
(`672aed5db411f31281f3f0c946a9373220dca7db` when refreshed) remains separate.

## Exact host composition and limits

Core was checked out clean at the exact candidate, with only its locked
production dependencies installed. No source overlay or Core dev/test fixture
was imported. Its production lock contains:

| Package | Version | Source |
| --- | --- | --- |
| ran/booster-github-provider | v1.0.0-beta.9 | 82ad810e8cde6a2f54318448e81685a619c2cfc3 |
| ran/updater-support | v1.0.0-beta.4 | 357db930b407941bd19a9e890c925d3d16ae9b15 |
| ran/wp-branch-updater | v1.0.0-beta.8 | 729a15c30f088236d0702b52d4a9cbe15c851508 |
| ran/wp-release-updater | v0.1.0-beta.7 | 203b4cf5e0bc133ff6664617cfd954d7e28dcc04 |

This qualifies Bitbucket against the candidate's public production contracts.
The API-12 GitHub Provider lock is not a matching full Core runtime composition;
this is not Core adoption, combined installed proof or permission to merge Core.
The coordinator published the rebased candidate in handoff 5942591785. Its only
delta from the previously tested `a53d35f18d226bf37f8485f351a5d0decdac6066`
is `pnpm-lock.yaml`; the API-13 interface/guard mapping and production Composer
lock are unchanged. The candidate pin and connected development instructions
now use that durable revision. The qualification below initially covered a53d35f;
refreshed exact-head/d18299fd checks are recorded in the PR conversation.
Never infer a tag or substitute source qualification for release proof.

## Mapping and receiver audit

| Class | Count | Renames |
| --- | ---: | --- |
| BitbucketCredentialPolicy | 4 | `getProvider` → `get_provider`; `normalizeCredential` → `normalize_credential`; `getConstantNames` → `get_constant_names`; `credentialFromConstants` → `credential_from_constants` |
| BitbucketWebhookPolicy | 8 | `getProvider` → `get_provider`; `getRetainedHeaders` → `get_retained_headers`; `getSignatureHeader` → `get_signature_header`; `normalizeWebhook` → `normalize_webhook`; `getConstantNames` → `get_constant_names`; `webhookFromConstants` → `webhook_from_constants`; `authorizeWebhook` → `authorize_webhook`; `repositoryTargetMatches` → `repository_target_matches` |
| BitbucketProvider | 12 | `getMetadata` → `get_metadata`; `getProviderDiagnostics` → `get_provider_diagnostics`; `getCredentialPolicy` → `get_credential_policy`; `getWebhookPolicy` → `get_webhook_policy`; `diagnoseWebhookReadiness` → `diagnose_webhook_readiness`; `validateCredential` → `validate_credential`; `browseRepositories` → `browse_repositories`; `getPublicRepositoryBrowseMetadata` → `get_public_repository_browse_metadata`; `resolveRepository` → `resolve_repository`; `prepareArchive` → `prepare_archive`; `normalizeWebhook` → `normalize_webhook`; `repositoryWebhookSettingsUrl` → `repository_webhook_settings_url` |
| BitbucketCredentialValidator | 1 | `validateCredential` → `validate_credential` |
| BitbucketWebhookNormalizer | 3 | `getWebhookPolicy` → `get_webhook_policy`; `diagnoseWebhookReadiness` → `diagnose_webhook_readiness`; `normalizeWebhook` → `normalize_webhook` |

Provider delegates to the mapped normalizer and validator methods; Diagnostics
calls the validator. CredentialPolicy and WebhookPolicy self-calls and the
normalizer's policy authorization call are migrated. All corresponding unit,
lifecycle and installed-smoke callers move with them. No matching string callback,
mock expectation or test-double declaration was found. Plugin callbacks and
unmapped registration/credential-store/evidence-reader contracts remain intact.

`WebhookRequest::getProvider` and `SignedWebhookVerification::getProvider` remain
camelCase because they are distinct unmigrated receivers. Public parameters,
DTO/wire/persisted fields, types/defaults/visibility, credential handling,
webhook authorization, request budgets and template identities are unchanged.
Named-argument tests retain `credentialId`, `timeout`, `response_size`,
`repositoryAuthorityId`, `repository`, `verification`, `target` and
`repositoryLocator`. No aliases are added.

## Qualification

PHP 8.3.6 local candidate host:

- `composer validate --strict --no-check-all --no-check-publish`: pass.
- `composer check:host`: pass, PHPStan level 8 without suppressions/baseline and 202 tests / 2,371 assertions.
- Release-candidate validator: 6 valid / 15 invalid cases pass; syntax and maintained-PHP coverage controls pass.
- Naming controls: production positive check and all 28 reversed declaration spellings fail as required; 28 obsolete method waivers removed.
- Two full configured PHPCBF cycles: byte-stable; no tracked changes.
- Exact-commit archive build/verification and hostile-archive unit tests: pass.
- Implementation ZIP: 42,031 bytes; SHA-256 `35795cad24fe805ff1ce9faf3da4c92df43a8a0c7605f713116d3ff5480a1e2d`.
- No vendor, Core, tests, Git data or credentials are shipped; existing archive allowlist remains intact.
- Independent local code/security-focused review: no blocking findings on the exact implementation base/head/tree above. Independently verified the mapping and behavior-only normalization; 47 targeted tests / 745 assertions pass. Actual-published-tuple review remains outstanding.

The fail-closed fixture observes every implementation autoload request and checks
class load state after the registration callback. Provider API 11, 12 and 14,
Add-on API 15 and 17, and the historical 11/15 pair reject in both load orders
before implementation loading, credential reads, scoped-service acquisition or
remote calls. Compatible API 13 / Add-on 16 registers in both orders. Workflow
V3 and optional capability declarations remain unchanged. Prepared installed
inertness controls include these boundaries but are not recorded as passed
installed-release evidence.

## Remaining admission and certification gates

1. Publish under Ben's explicit branch/draft-PR approval, verify base/head/tree, and link #89 without losing its history.
2. Run native PHP 8.2/8.5 Quality and obtain hosted review and independent actual-published-tuple review. Local PHP 8.3.6 is not substituted for those runs.
3. Refresh the final durable Core API-13 composition from the coordinator. Update the source candidate pin and rerun affected host checks if it differs.
4. Coordinator releases the matching Core only after its own dependency composition, archive, installed gates and separate release approval. As checked during this work, latest Core beta.30 still declares Provider API 11 / Add-on 16; no matching API-13 release exists.
5. After actual immutable API-13 Core publication, record its real tag, tag-target commit and archive-source commit in `extra.ran-booster-core-certification`, plus verified release assets/digests. Current beta.29 certification remains historical API-11 evidence and is unchanged.
6. Restore release-backed host qualification (remove candidate mode from the required Quality host lane and use the actual certification source), run full host checks and `Certified Core installed proof` against the immutable release/ZIP, including normal activation, both inertness boundaries, authenticated delivery and installed byte readback.
7. Only then request the separate source merge/release #75 decisions. Refresh release-generated identities, exact tested ZIP promotion and immutable readback. Source preparation is not publication or certification.

The unchanged historical certified-installed lane is expected to fail the API-13
contract boundary; no new installed run is represented as passed or waived.

## Changed paths (base to implementation)

- `.github/workflows/quality.yml`
- `AGENTS.md`
- `CONTRIBUTING.md`
- `README.md`
- `RELEASE.md`
- `composer.json`
- `composer.lock`
- `readme.txt`
- `scripts/verify-release.php`
- `src/Bitbucket/BitbucketCredentialPolicy.php`
- `src/Bitbucket/BitbucketCredentialValidator.php`
- `src/Bitbucket/BitbucketDiagnostics.php`
- `src/Bitbucket/BitbucketProvider.php`
- `src/Bitbucket/BitbucketWebhookNormalizer.php`
- `src/Bitbucket/BitbucketWebhookPolicy.php`
- `src/Bitbucket/Plugin.php`
- `tests/Plugin/CoreContractTest.php`
- `tests/Plugin/CurrentCoreBoundaryTest.php`
- `tests/Plugin/PluginCompatibilityTest.php`
- `tests/Plugin/QualityWorkflowFanInContractTest.php`
- `tests/RepositoryProvider/BitbucketCredentialValidatorTest.php`
- `tests/RepositoryProvider/BitbucketDiagnosticsBoundaryTest.php`
- `tests/RepositoryProvider/BitbucketRepositoryBrowserTest.php`
- `tests/RepositoryProvider/BitbucketWebhookDeliveryEvidenceTest.php`
- `tests/RepositoryProvider/BitbucketWebhookNormalizerTest.php`
- `tests/RepositoryProvider/BitbucketWebhookPolicyTest.php`
- `tests/WordPress/bitbucket-installed-inert.php`
- `tests/WordPress/bitbucket-installed-proof.sh`
- `tests/WordPress/bitbucket-installed-smoke.php`
- `tests/Workflow/product-php-coverage.sh`
- `tests/Workflow/provider-api13-naming.sh`
- `tests/fixtures/certified-core-checkout.php`
- `tests/fixtures/plugin-lifecycle.php`
