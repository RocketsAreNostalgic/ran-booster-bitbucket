# Beta.31 consumer recovery — 2 October 2026

The original published recovery branch is preserved. This receiver completes exact
boundary guards and consumer fixtures for Provider 14 / Add-on 17 / Admin Interaction 3.
Core keeps Portability 3 and its existing hash domain. Prospective Release 8 remains
Core-owned; these satellite runtime guards do not claim that capability.

Source qualification on PHP 8.3.6 against Core local checkpoint
`80cb1569a1f595518bd53fbb8780adae272f77df`: 202 tests / 2,371 assertions; PHPStan level 8 and composer check pass.
The coordinator published the identical Core tree at
`36ea3fcee380b0869c8ca8bd83270408f4c6f2d3`. This is candidate-source evidence only. Final Core changes
require renewed exact-pair qualification before landing.

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
