# Changelog

All notable changes are documented here. Release Please maintains released
sections from Conventional Commits.

## [0.1.0-alpha.4](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/compare/v0.1.0-alpha.3...v0.1.0-alpha.4) (2026-07-31)


### Bug Fixes

* normalize release ZIP timestamps to UTC ([#10](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/issues/10)) ([53d2bbc](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/53d2bbc458631dfa6e8b84c8763074a7d93ab77d))

## [0.1.0-alpha.3](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/compare/v0.1.0-alpha.2...v0.1.0-alpha.3) (2026-07-31)


### Bug Fixes

* remove redundant Composer version source ([#9](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/issues/9)) ([d046910](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/d046910e0b4f309d8799477850fb1ef268aa6a27))
* support Booster add-on API 11 ([#7](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/issues/7)) ([b910167](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/b91016701ef56a85f38b8b44ae5246f5d38e9840))

## [0.1.0-alpha.2](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/compare/v0.1.0-alpha.1...v0.1.0-alpha.2) (2026-07-29)


### ⚠ BREAKING CHANGES

* Provider API 6, Logging API 1, and Add-on API 7 are required.

### Features

* **admin:** add Bitbucket documentation guide ([93d2506](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/93d25066d8684670e1f234214b27033f70d42647))
* declare Booster plugin dependency ([e4d0055](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/e4d00550cbd262ecda335dd53d1890afdfc478b7))
* extract Bitbucket Cloud provider add-on ([91cd2dd](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/91cd2ddc5a345e364fc9b889005d0691f36041a2))
* require Provider API 6 and native documentation hooks ([5b06dd0](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/5b06dd0e1df27e4a3493933709985c6f222b568a))
* route Bitbucket diagnostics through Core logging ([387651d](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/387651dc165a33f6e5931eefe3abe6436cff86be))
* support PHP 8.2 ([0d9a022](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/0d9a0224ae0e721d043b2bebb421c966a882063d))


### Bug Fixes

* **ci:** require private Core deploy key ([2e35b41](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/2e35b417cf047e8b189e8882df0ddd9fc9a05d53))
* keep Bitbucket inert on unsupported multisite ([cea275e](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/cea275e434afd9359a090a47c51402cafc3a72c5))
* require Booster Add-on API 9 ([a758bcf](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/a758bcf13054ca538dd2959975c4a156478e5abe))
* **webhooks:** align Bitbucket secret scopes ([6ed8c82](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/6ed8c82efdeffc481ac4172ad8a49103b900e378))


### Miscellaneous Chores

* add Bitbucket release standard ([03dfcfa](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/03dfcfab42da3b679941018fe93ed4213847e99d))
* **ci:** use Node 24-native release action ([c432550](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/c432550bd0a6a261daa5108073151c7101c123f3))
* **deps:** update WPCS security patch ([#2](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/issues/2)) ([7363143](https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/commit/7363143a36a1da7f81942a8c9d8831221029b5c6))

## 0.1.0-alpha.1

- Initial standalone Bitbucket Cloud provider extraction for RAN Booster Provider API 5.
