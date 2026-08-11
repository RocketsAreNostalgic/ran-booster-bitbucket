#!/usr/bin/env bash

set -euo pipefail

fail() {
	printf 'bitbucket-installed-proof: %s\n' "$*" >&2
	exit 1
}

[[ "${RAN_BOOSTER_BITBUCKET_TEST_DISPOSABLE:-}" == 1 ]] \
	|| fail 'Set RAN_BOOSTER_BITBUCKET_TEST_DISPOSABLE=1 only for an isolated disposable WordPress installation.'

wordpress=${RAN_BOOSTER_WORDPRESS_PATH:?RAN_BOOSTER_WORDPRESS_PATH is required}
core_archive=${RAN_BOOSTER_CORE_ARCHIVE:?RAN_BOOSTER_CORE_ARCHIVE is required}
core_source=${RAN_BOOSTER_CORE_SOURCE_PATH:?RAN_BOOSTER_CORE_SOURCE_PATH is required}
addon_archive=${RAN_BOOSTER_BITBUCKET_ARCHIVE:?RAN_BOOSTER_BITBUCKET_ARCHIVE is required}
addon_commit=${RAN_BOOSTER_BITBUCKET_COMMIT:?RAN_BOOSTER_BITBUCKET_COMMIT is required}
expected_core_sha=${RAN_BOOSTER_CORE_SHA256:?RAN_BOOSTER_CORE_SHA256 is required}
expected_addon_sha=${RAN_BOOSTER_BITBUCKET_SHA256:?RAN_BOOSTER_BITBUCKET_SHA256 is required}
php_binary=${RAN_BOOSTER_WP_CLI_PHP:-php}
wp_binary=${RAN_BOOSTER_WP_CLI_BIN:-wp}
wp_require=${RAN_BOOSTER_WP_CLI_REQUIRE:-}
php_ini=${RAN_BOOSTER_WP_CLI_PHP_INI:-}

marker="$wordpress/.ran-booster-disposable-test-site"
[[ -f "$marker" && ! -L "$marker" ]] \
	|| fail 'The disposable-site marker is missing or unsafe.'
[[ "$(< "$marker")" == 'RAN Booster disposable test site' ]] \
	|| fail 'The disposable-site marker is invalid.'
[[ -f "$core_archive" && ! -L "$core_archive" ]] || fail 'The exact Core archive is unavailable.'
[[ -f "$addon_archive" && ! -L "$addon_archive" ]] || fail 'The exact Bitbucket archive is unavailable.'
[[ -d "$core_source/.git" ]] || fail 'The exact Core source checkout is unavailable.'
[[ "$(git -C "$core_source" rev-parse HEAD)" == c992d612a827bef2bc6dea6993e25045087b6d52 ]] \
	|| fail 'The Core source checkout is not the certified commit.'
[[ "$addon_commit" =~ ^[0-9a-f]{40}$ ]] || fail 'The Bitbucket source commit must be full.'

sha256_file() {
	shasum -a 256 "$1" | awk '{ print $1 }'
}

[[ "$(sha256_file "$core_archive")" == "$expected_core_sha" ]] || fail 'The Core archive digest is wrong.'
[[ "$(sha256_file "$addon_archive")" == "$expected_addon_sha" ]] || fail 'The Bitbucket archive digest is wrong.'
unzip -tqq "$core_archive"
unzip -tqq "$addon_archive"

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
repo_root=$(git -C "$script_dir" rev-parse --show-toplevel)
bash "$repo_root/scripts/verify-release.sh" "$addon_archive" "$addon_commit"

wp_cli() {
	local command=( "$php_binary" )
	[[ -z "$php_ini" ]] || command+=( -c "$php_ini" )
	command+=( "$wp_binary" )
	[[ -z "$wp_require" ]] || command+=( "--require=$wp_require" )
	command+=( "--path=$wordpress" )
	"${command[@]}" "$@"
}

[[ "$(wp_cli option get siteurl | tail -n 1)" == 'http://localhost:10028' ]] \
	|| fail 'The disposable WordPress URL is not the authorized site.'

plugins="$wordpress/wp-content/plugins"
core_dir="$plugins/ran-booster"
addon_dir="$plugins/ran-booster-bitbucket"
[[ -d "$core_dir" && ! -L "$core_dir" ]] || fail 'The baseline Core directory is unavailable or unsafe.'
[[ ! -e "$addon_dir" && ! -L "$addon_dir" ]] || fail 'Bitbucket is already present; refusing to overwrite an unowned installation.'

temporary=$(mktemp -d /private/tmp/ran-booster-bitbucket-installed.XXXXXX)
baseline_active=$(wp_cli option get active_plugins --format=json | tail -n 1)
cp -R "$core_dir" "$temporary/original-core"

cleanup() {
	local status=$?
	local cleanup_failed=0
	local restored_active=''
	trap - EXIT HUP INT TERM
	set +e
	if [[ -d "$addon_dir" && ! -L "$addon_dir" ]]; then
		mv "$addon_dir" "$temporary/proof-addon" || cleanup_failed=1
	fi
	if [[ -d "$core_dir" && ! -L "$core_dir" ]]; then
		mv "$core_dir" "$temporary/proof-core" || cleanup_failed=1
	fi
	if [[ -d "$temporary/original-core" && ! -L "$temporary/original-core" ]]; then
		mv "$temporary/original-core" "$core_dir" || cleanup_failed=1
	else
		cleanup_failed=1
	fi
	wp_cli option update active_plugins "$baseline_active" --format=json >/dev/null || cleanup_failed=1
	restored_active=$(wp_cli option get active_plugins --format=json | tail -n 1)
	[[ "$restored_active" == "$baseline_active" ]] || cleanup_failed=1
	[[ ! -e "$addon_dir" && ! -L "$addon_dir" && -d "$core_dir" && ! -L "$core_dir" ]] || cleanup_failed=1
	if (( 0 == cleanup_failed )); then
		case "$temporary" in
			/private/tmp/ran-booster-bitbucket-installed.*) rm -rf -- "$temporary" ;;
		esac
	elif (( 0 == status )); then
		printf 'bitbucket-installed-proof: cleanup failed; retained recovery data at %s\n' "$temporary" >&2
		status=1
	fi
	exit "$status"
}
trap cleanup EXIT HUP INT TERM

wp_cli plugin deactivate --all >/dev/null
wp_cli plugin install "$core_archive" --force >/dev/null
wp_cli plugin install "$addon_archive" >/dev/null
wp_cli plugin activate ran-booster >/dev/null
wp_cli plugin activate ran-booster-bitbucket >/dev/null

extracted="$temporary/extracted"
mkdir "$extracted"
unzip -q "$addon_archive" -d "$extracted"
diff -qr "$extracted/ran-booster-bitbucket" "$addon_dir"

wp_cli option update active_plugins '["ran-booster/ran-booster.php","ran-booster-bitbucket/ran-booster-bitbucket.php"]' --format=json >/dev/null
export RAN_BOOSTER_BITBUCKET_LOAD_ORDER=core-first
export RAN_BOOSTER_CORE_SOURCE_PATH="$core_source"
wp_cli eval-file "$script_dir/bitbucket-installed-smoke.php" --user=admin

wp_cli option update active_plugins '["ran-booster-bitbucket/ran-booster-bitbucket.php","ran-booster/ran-booster.php"]' --format=json >/dev/null
export RAN_BOOSTER_BITBUCKET_LOAD_ORDER=addon-first
wp_cli eval-file "$script_dir/bitbucket-installed-smoke.php" --user=admin
unset RAN_BOOSTER_BITBUCKET_LOAD_ORDER

export RAN_BOOSTER_BITBUCKET_INERT_MODE=absent
wp_cli eval-file "$script_dir/bitbucket-installed-inert.php" --skip-plugins --user=admin
export RAN_BOOSTER_BITBUCKET_INERT_MODE=incompatible
wp_cli eval-file "$script_dir/bitbucket-installed-inert.php" --skip-plugins --user=admin
unset RAN_BOOSTER_BITBUCKET_INERT_MODE

printf 'Bitbucket installed proof passed for %s (%s).\n' "$addon_commit" "$expected_addon_sha"
