# Beta.31 consumer recovery — 2 October 2026

The original published recovery branch is preserved. This receiver completes exact
boundary guards and consumer fixtures for Provider 14 / Add-on 17 / Admin Interaction 3.
Core keeps Portability 3 and its existing hash domain. Prospective Release 8 remains
Core-owned; these satellite runtime guards do not claim that capability.

Final source qualification uses published Core
`ae4de158e3ae02d99162b9b8d0babdc9269a36da` (Core draft PR #224),
whose tree `f5ad8aa484fbe63b7d966eeef96ae9afd5d78a94` exactly matches
local checkpoint `fa5265731f50dad658d1c1bda63ad3cca7168a42`.
On PHP 8.3.6, Provider has 432 passing tests / 3,354 assertions and PHPStan;
Bitbucket has 202 passing tests / 2,371 assertions and level 8 PHPStan;
Migrator has 128 passing tests / 1,462 assertions, level 6 exact-source analysis,
and behavior output identical to the previous source checkpoint, including
fingerprints, nonce strings and cleanup outcomes. This is candidate-source
evidence only; the final published pair remains subject to hosted matrix/review.

The historical released-Core certification tuples remain unchanged. Neither these
source proofs nor this recovery grant installed-site acceptance, release certification,
merge approval, or beta.31 publication. Matching immutable Core release certification,
PHP 8.2/8.5 hosted matrix, and existing installed acceptance remain outstanding.

Canonical checks after retrying existing fixtures outside the sandbox:

- Provider `composer check`: PASS, including all 33 Node release-control tests.
- Bitbucket `composer check`: PASS, including syntax/coverage/naming negatives.
- Migrator `composer check`: PHP syntax and PHPCS pass; historical Core fetching and
  exact certification verification pass, then PHPStan correctly reports 13 errors
  because the historical API 2 declarations do not provide the new snake_case
  Portability 3 / Admin Interaction 3 surface. Its source-candidate level 6 proof
  passes. The canonical released-host gate remains held until matching immutable
  Core recertification; no fallback, baseline, or suppression was introduced.

## Source merge and release admission separation

The source PR is qualified against exact Core
`ae4de158e3ae02d99162b9b8d0babdc9269a36da`; independent review and native
candidate-source Quality authorize source readiness, not immutable certification.
After the workflow separation, fresh `composer check:host` passes on PHP 8.3.6,
including level 8 analysis, 203 tests / 2,394 assertions, release-candidate controls,
syntax, production standards and coverage/naming negative controls.

The former standalone PR installed proof is now a reusable release-admission gate.
After successful same-repository main push Quality, the release caller supplies its
exact head SHA to the unchanged immutable-Core download and disposable WordPress
proof. The shared Profile B job depends on success. Historical beta.29 cannot pass
that gate; no certification marker, archive identity or dependency pin is changed.
Manual proof dispatch remains diagnostic and cannot publish a release.
