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
addon_archive=${RAN_BOOSTER_BITBUCKET_ARCHIVE:?RAN_BOOSTER_BITBUCKET_ARCHIVE is required}
addon_commit=${RAN_BOOSTER_BITBUCKET_COMMIT:?RAN_BOOSTER_BITBUCKET_COMMIT is required}
expected_addon_version=${RAN_BOOSTER_BITBUCKET_VERSION:?RAN_BOOSTER_BITBUCKET_VERSION is required}
proof_mode=${RAN_BOOSTER_BITBUCKET_PROOF_MODE:-certified}
[[ "$proof_mode" == certified || "$proof_mode" == additional-host ]] || fail 'Unknown proof mode.'
provided_core_tag=${RAN_BOOSTER_CORE_TAG:-}
provided_core_commit=${RAN_BOOSTER_CORE_COMMIT:-}
provided_core_archive_source_commit=${RAN_BOOSTER_CORE_ARCHIVE_SOURCE_COMMIT:-}
expected_core_sha=${RAN_BOOSTER_CORE_SHA256:?RAN_BOOSTER_CORE_SHA256 is required}
expected_addon_sha=${RAN_BOOSTER_BITBUCKET_SHA256:?RAN_BOOSTER_BITBUCKET_SHA256 is required}
php_binary=${RAN_BOOSTER_WP_CLI_PHP:-php}
wp_binary=${RAN_BOOSTER_WP_CLI_BIN:-wp}
wp_require=${RAN_BOOSTER_WP_CLI_REQUIRE:-}
php_ini=${RAN_BOOSTER_WP_CLI_PHP_INI:-}
temporary_parent=${RAN_BOOSTER_BITBUCKET_TEST_TMPDIR:-${RUNNER_TEMP:-/private/tmp}}
script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
repo_root=$(git -C "$script_dir" rev-parse --show-toplevel)

if ! certification=$(
	"$php_binary" -r '
		$document = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
		$certification = $document["extra"]["ran-booster-core-certification"] ?? null;
		$tag = is_array( $certification ) ? ( $certification["tag"] ?? null ) : null;
		$commit = is_array( $certification ) ? ( $certification["commit"] ?? null ) : null;
		$archiveSourceCommit = is_array( $certification ) ? ( $certification["archive-source-commit"] ?? null ) : null;
		if ( ! is_string( $tag ) || ! is_string( $commit ) || ! is_string( $archiveSourceCommit ) ) {
			exit( 1 );
		}
		echo $tag, "|", $commit, "|", $archiveSourceCommit;
	' "$repo_root/composer.json"
); then
	fail 'The canonical Core certification record is unavailable.'
fi
IFS='|' read -r expected_core_tag expected_core_commit expected_core_archive_source_commit <<< "$certification"

[[ "$expected_addon_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] \
	|| fail 'The expected Bitbucket version is invalid.'
[[ "$expected_core_tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] \
	|| fail 'The canonical Core tag is invalid.'
[[ "$expected_core_commit" =~ ^[0-9a-f]{40}$ ]] \
	|| fail 'The canonical Core commit must be full.'
[[ "$expected_core_archive_source_commit" =~ ^[0-9a-f]{40}$ ]] \
	|| fail 'The canonical Core archive source commit must be full.'
if [[ "$proof_mode" == additional-host ]]; then
	[[ -n "$provided_core_tag" && -n "$provided_core_commit" && -n "$provided_core_archive_source_commit" ]] || fail 'The additional-host tuple must be supplied in full.'
	expected_core_tag=$provided_core_tag
	expected_core_commit=$provided_core_commit
	expected_core_archive_source_commit=$provided_core_archive_source_commit
	[[ "$expected_core_tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ && "$expected_core_commit" =~ ^[0-9a-f]{40}$ && "$expected_core_archive_source_commit" =~ ^[0-9a-f]{40}$ ]] || fail 'The additional-host tuple is invalid.'
else
	[[ -z "$provided_core_tag" || "$provided_core_tag" == "$expected_core_tag" ]] || fail 'The supplied Core tag does not match the canonical certification.'
	[[ -z "$provided_core_commit" || "$provided_core_commit" == "$expected_core_commit" ]] || fail 'The supplied Core commit does not match the canonical certification.'
	[[ -z "$provided_core_archive_source_commit" || "$provided_core_archive_source_commit" == "$expected_core_archive_source_commit" ]] || fail 'The supplied Core archive source commit does not match the canonical certification.'
fi
[[ "$addon_commit" =~ ^[0-9a-f]{40}$ ]] || fail 'The Bitbucket source commit must be full.'

marker="$wordpress/.ran-booster-disposable-test-site"
[[ -f "$marker" && ! -L "$marker" ]] \
	|| fail 'The disposable-site marker is missing or unsafe.'
[[ "$(< "$marker")" == 'RAN Booster disposable test site' ]] \
	|| fail 'The disposable-site marker is invalid.'
[[ -f "$core_archive" && ! -L "$core_archive" ]] || fail 'The exact Core archive is unavailable.'
[[ -f "$addon_archive" && ! -L "$addon_archive" ]] || fail 'The exact Bitbucket archive is unavailable.'
[[ -d "$temporary_parent" && ! -L "$temporary_parent" ]] || fail 'The disposable temporary parent is unavailable or unsafe.'

sha256_file() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{ print $1 }'
		return
	fi

	shasum -a 256 "$1" | awk '{ print $1 }'
}

[[ "$(sha256_file "$core_archive")" == "$expected_core_sha" ]] || fail 'The Core archive digest is wrong.'
[[ "$(sha256_file "$addon_archive")" == "$expected_addon_sha" ]] || fail 'The Bitbucket archive digest is wrong.'
if [[ "$proof_mode" == additional-host ]]; then
	proof_root=${RAN_BOOSTER_BITBUCKET_PROOF_ROOT:?RAN_BOOSTER_BITBUCKET_PROOF_ROOT is required}
	proof_url=${RAN_BOOSTER_BITBUCKET_PROOF_URL:?RAN_BOOSTER_BITBUCKET_PROOF_URL is required}
	proof_db=${RAN_BOOSTER_BITBUCKET_PROOF_DB_NAME:?RAN_BOOSTER_BITBUCKET_PROOF_DB_NAME is required}
	proof_socket=${RAN_BOOSTER_BITBUCKET_PROOF_DB_SOCKET:?RAN_BOOSTER_BITBUCKET_PROOF_DB_SOCKET is required}
	[[ -z "$wp_require" ]] || fail 'Additional-host proof cannot use a WP-CLI require file.'
	[[ "$proof_url" =~ ^http://127\.0\.0\.1:[0-9]+$ && "$proof_db" =~ ^[A-Za-z0-9_]+$ ]] || fail 'Unsafe additional-host URL or database name.'
	export RAN_BOOSTER_BITBUCKET_PROOF_ROOT="$proof_root" RAN_BOOSTER_BITBUCKET_PROOF_URL="$proof_url" RAN_BOOSTER_BITBUCKET_PROOF_DB_NAME="$proof_db" RAN_BOOSTER_BITBUCKET_PROOF_DB_SOCKET="$proof_socket"
fi

if [[ "$proof_mode" == additional-host ]]; then
	[[ ( "$proof_root" == /private/tmp/ran528-* || "$proof_root" == /tmp/ran528-* ) && -d "$proof_root" && ! -L "$proof_root" && "$(cd "$proof_root" && pwd -P)" == "$proof_root" ]] || fail 'Unsafe proof root.'
	[[ -f "$proof_root/.ran528-owner" && ! -L "$proof_root/.ran528-owner" && "$(< "$proof_root/.ran528-owner")" == RAN528_COMPATIBILITY_PILOT_V1 ]] || fail 'Invalid proof ownership marker.'
fi

unzip -tqq "$core_archive"
unzip -tqq "$addon_archive"

if ! unzip -p "$core_archive" ran-booster/ran-booster-release.json \
	| "$php_binary" -r '
		$document = json_decode( stream_get_contents( STDIN ), true, 512, JSON_THROW_ON_ERROR );
		$tag = $argv[1];
		$commit = $argv[2];
		if ( ! is_array( $document )
			|| "ran-booster-core-release" !== ( $document["schema"] ?? null )
			|| 1 !== ( $document["schema_version"] ?? null )
			|| substr( $tag, 1 ) !== ( $document["version"] ?? null )
			|| $commit !== ( $document["commit"] ?? null )
		) {
			exit( 1 );
		}
	' "$expected_core_tag" "$expected_core_archive_source_commit"; then
	fail 'The Core archive does not match the canonical certified release provenance.'
fi

bash "$repo_root/scripts/verify-release.sh" "$addon_archive" "$addon_commit"

wp_cli_raw() {
	local wp_entry=$wp_binary
	local command=( "$php_binary" )

	if [[ "$wp_entry" != */* ]]; then
		wp_entry=$(command -v "$wp_entry") \
			|| fail 'The WP-CLI launcher is unavailable.'
	fi

	[[ -z "$php_ini" ]] || command+=( -c "$php_ini" )
	command+=( "$wp_entry" )
	[[ -z "$wp_require" ]] || command+=( "--require=$wp_require" )
	command+=( "--path=$wordpress" )
	"${command[@]}" "$@"
}

owned_boundary() {
	[[ "$proof_mode" == additional-host ]] || return 0
	[[ ( "$proof_root" == /private/tmp/ran528-* || "$proof_root" == /tmp/ran528-* ) && -d "$proof_root" && ! -L "$proof_root" && "$(cd "$proof_root" && pwd -P)" == "$proof_root" ]] || fail 'Unsafe proof root.'
	[[ -f "$proof_root/.ran528-owner" && ! -L "$proof_root/.ran528-owner" && "$(< "$proof_root/.ran528-owner")" == RAN528_COMPATIBILITY_PILOT_V1 ]] || fail 'Invalid proof ownership marker.'
	[[ -d "$wordpress" && ! -L "$wordpress" && "$(cd "$wordpress" && pwd -P)" == "$wordpress" && "$wordpress" == "$proof_root"/* && "$repo_root" != "$wordpress" && "$repo_root" != "$wordpress"/* ]] || fail 'Unsafe WordPress path.'
	[[ -z "$(find "$wordpress" -type l -print -quit)" ]] || fail 'WordPress tree contains a symlink.'
	[[ -S "$proof_socket" && ! -L "$proof_socket" && "$(cd "$(dirname "$proof_socket")" && pwd -P)/$(basename "$proof_socket")" == "$proof_socket" && "$proof_socket" == "$proof_root"/* ]] || fail 'Unsafe database socket.'
	wp_cli_raw eval --skip-plugins --skip-themes '
		global $wpdb;
		$site = rtrim(getenv("RAN_BOOSTER_WORDPRESS_PATH"), "/");
		$socket = getenv("RAN_BOOSTER_BITBUCKET_PROOF_DB_SOCKET");
		if (rtrim(ABSPATH, "/") !== $site || realpath(ABSPATH) !== $site || realpath(WP_CONTENT_DIR) !== $site . "/wp-content" || realpath(WP_PLUGIN_DIR) !== $site . "/wp-content/plugins" || WP_CONTENT_DIR !== $site . "/wp-content" || WP_PLUGIN_DIR !== $site . "/wp-content/plugins" || get_theme_root() !== $site . "/wp-content/themes" || realpath(get_theme_root()) !== $site . "/wp-content/themes" || get_option("siteurl") !== getenv("RAN_BOOSTER_BITBUCKET_PROOF_URL") || get_option("home") !== getenv("RAN_BOOSTER_BITBUCKET_PROOF_URL") || DB_NAME !== getenv("RAN_BOOSTER_BITBUCKET_PROOF_DB_NAME") || DB_HOST !== "localhost:" . $socket || $wpdb->get_var("SELECT @@socket") !== $socket || realpath((string) $wpdb->get_var("SELECT @@datadir")) !== dirname($socket) . "/data") { throw new RuntimeException("Unowned WordPress boundary"); }
	' >/dev/null || fail 'WordPress ownership preflight failed.'
}
wp_cli() {
	owned_boundary
	wp_cli_raw "$@"
}

if [[ "$proof_mode" == additional-host ]]; then
	owned_boundary
else
	[[ "$(wp_cli option get siteurl | tail -n 1)" == 'http://localhost:10028' ]] || fail 'The disposable WordPress URL is not the authorized site.'
fi

plugins="$wordpress/wp-content/plugins"
core_dir="$plugins/ran-booster"
addon_dir="$plugins/ran-booster-bitbucket"
[[ -d "$core_dir" && ! -L "$core_dir" ]] || fail 'The baseline Core directory is unavailable or unsafe.'
[[ ! -e "$addon_dir" && ! -L "$addon_dir" ]] || fail 'Bitbucket is already present; refusing to overwrite an unowned installation.'

temporary=$(mktemp -d "$temporary_parent/ran-booster-bitbucket-installed.XXXXXX")
baseline_active=$(wp_cli option get active_plugins --format=json | tail -n 1)
cp -R "$core_dir" "$temporary/original-core"

cleanup() {
	local status=$?
	local cleanup_failed=0
	local restored_active=''
	trap - EXIT HUP INT TERM
	set +e
	if [[ "$proof_mode" == additional-host ]] && ! ( owned_boundary ); then
		printf 'bitbucket-installed-proof: ownership changed; retained recovery data at %s\n' "$temporary" >&2
		exit 1
	fi
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
			"$temporary_parent"/ran-booster-bitbucket-installed.*) rm -rf -- "$temporary" ;;
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
unzip -q "$core_archive" -d "$extracted"
diff -qr "$extracted/ran-booster" "$core_dir"
diff -qr "$extracted/ran-booster-bitbucket" "$addon_dir"

wp_cli option update active_plugins '["ran-booster/ran-booster.php","ran-booster-bitbucket/ran-booster-bitbucket.php"]' --format=json >/dev/null
export RAN_BOOSTER_BITBUCKET_LOAD_ORDER=core-first
export RAN_BOOSTER_BITBUCKET_VERSION="$expected_addon_version"
wp_cli eval-file "$script_dir/bitbucket-installed-smoke.php" --user=admin

wp_cli option update active_plugins '["ran-booster-bitbucket/ran-booster-bitbucket.php","ran-booster/ran-booster.php"]' --format=json >/dev/null
export RAN_BOOSTER_BITBUCKET_LOAD_ORDER=addon-first
wp_cli eval-file "$script_dir/bitbucket-installed-smoke.php" --user=admin
unset RAN_BOOSTER_BITBUCKET_LOAD_ORDER

export RAN_BOOSTER_BITBUCKET_INERT_MODE=absent
wp_cli eval-file "$script_dir/bitbucket-installed-inert.php" --skip-plugins --user=admin
for inert_mode in incompatible provider-fourteen provider-sixteen addon-sixteen addon-eighteen; do
	export RAN_BOOSTER_BITBUCKET_INERT_MODE="$inert_mode"
	wp_cli eval-file "$script_dir/bitbucket-installed-inert.php" --skip-plugins --user=admin
done
unset RAN_BOOSTER_BITBUCKET_INERT_MODE

diff -qr "$extracted/ran-booster" "$core_dir"
diff -qr "$extracted/ran-booster-bitbucket" "$addon_dir"
if [[ "$proof_mode" == additional-host ]]; then
	printf 'Bitbucket additional-host qualification passed for %s (%s) against Core %s (supplied tag target %s, archive source %s).\n' "$addon_commit" "$expected_addon_sha" "$expected_core_tag" "$expected_core_commit" "$expected_core_archive_source_commit"
	exit 0
fi
printf 'Bitbucket installed proof passed for %s (%s) against certified Core %s (tag %s, archive %s).\n' \
	"$addon_commit" "$expected_addon_sha" "$expected_core_tag" "$expected_core_commit" "$expected_core_archive_source_commit"
