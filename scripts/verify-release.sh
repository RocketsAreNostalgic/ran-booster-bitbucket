#!/usr/bin/env bash

set -euo pipefail

archive=${1:?archive is required}
root=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)
cd "$root"

case "$archive" in
	/*) ;;
	*) archive="$root/$archive" ;;
esac

test -f "$archive"
unzip -tqq "$archive"
checksum="$archive.sha256"
test -f "$checksum"
archive_name=$(basename "$archive")
expected_checksum=$(shasum -a 256 "$archive" | awk -v name="$archive_name" '{ print $1 "  " name }')
if [[ "$(< "$checksum")" != "$expected_checksum" ]]; then
	echo "Release checksum must contain only the archive basename." >&2
	exit 1
fi
( cd "$(dirname "$archive")" && shasum -a 256 -c "$(basename "$checksum")" ) >/dev/null

header_version=$(unzip -p "$archive" ran-booster-bitbucket/ran-booster-bitbucket.php | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p')
readme_version=$(unzip -p "$archive" ran-booster-bitbucket/readme.txt | sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p')

if [ -z "$header_version" ] || [ "$header_version" != "$readme_version" ]; then
	echo "Archive header and readme stable tag must match." >&2
	exit 1
fi

expected=$(mktemp)
actual=$(mktemp)
temporary=''
trap 'rm -f "$expected" "$actual"; rm -rf "${temporary:-}"' EXIT HUP INT TERM

while IFS= read -r file; do
	case "$file" in
		''|'#'*) continue ;;
	esac
	git ls-tree -r --name-only HEAD -- "$file" | sed 's#^#ran-booster-bitbucket/#'
done < release-files.txt | LC_ALL=C sort -u > "$expected"

unzip -Z1 "$archive" | sed '/\/$/d' | LC_ALL=C sort -u > "$actual"

if ! diff -u "$expected" "$actual"; then
	echo "Archive contents do not exactly match release-files.txt." >&2
	exit 1
fi

if unzip -Z1 "$archive" | grep -Eq '(^|/)(tests|vendor|build|scripts|\.github|\.git|\.phpunit\.cache|composer\.json|composer\.lock|AGENTS\.md|RELEASE\.md|CONTRIBUTING\.md)(/|$)'; then
	echo "Archive contains prohibited development, Core, or vendor paths." >&2
	exit 1
fi

plugin=$(unzip -p "$archive" ran-booster-bitbucket/ran-booster-bitbucket.php)
guide=$(unzip -p "$archive" ran-booster-bitbucket/views/documentation.php)

if ! grep -Fq "RAN_BOOSTER_PROVIDER_API_VERSION" <<< "$plugin" \
	|| ! grep -Fq "8 !== RAN_BOOSTER_PROVIDER_API_VERSION" <<< "$plugin" \
	|| ! grep -Fq "RAN_BOOSTER_ADDON_API_VERSION" <<< "$plugin" \
	|| ! grep -Fq "14 !== RAN_BOOSTER_ADDON_API_VERSION" <<< "$plugin" \
	|| ! grep -Fq "ran_booster_documentation_sections_after_provider_bb" <<< "$plugin"; then
	echo "Archive must require Provider API 8 and Add-on API 14, and register the native Bitbucket documentation filter." >&2
	exit 1
fi
if grep -Fq 'RAN_BOOSTER_LOGGING_API_VERSION' <<< "$plugin"; then
	echo "Archive contains the removed Core logging marker." >&2
	exit 1
fi

if ! grep -Fq "ran-booster-documentation-bitbucket-cloud" <<< "$plugin"; then
	echo "Archive Bitbucket documentation section registration is incomplete." >&2
	exit 1
fi

for required_guide_text in \
	"Repositories: Read (read:repository:bitbucket)" \
	"Set up Push-to-Deploy manually" \
	"Move or recover a package with Transporter" \
	"Deactivation, deletion and provider cleanup" \
	"Private releases and support"; do
	if ! grep -Fq "$required_guide_text" <<< "$guide"; then
		echo "Archive Bitbucket guide is incomplete: missing $required_guide_text." >&2
		exit 1
	fi
done

if grep -Eq '<(form|input|button)([[:space:]>])|wp_nonce|admin_post_' <<< "$guide"; then
	echo "Archive Bitbucket guide must remain non-interactive." >&2
	exit 1
fi

temporary=$(mktemp -d)
unzip -q "$archive" -d "$temporary"
while IFS= read -r file; do
	php -l "$file" >/dev/null
done < <(find "$temporary/ran-booster-bitbucket" -type f -name '*.php' -print)
