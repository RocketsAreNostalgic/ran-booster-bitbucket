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

header_version=$(unzip -p "$archive" ran-booster-bitbucket/ran-booster-bitbucket.php | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p')
readme_version=$(unzip -p "$archive" ran-booster-bitbucket/readme.txt | sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p')
composer_version=$(php -r '$manifest = json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR); echo $manifest["version"] ?? "";')

if [ -z "$header_version" ] || [ "$header_version" != "$readme_version" ] || [ "$header_version" != "$composer_version" ]; then
	echo "Archive header, readme stable tag, and Composer version must match." >&2
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

temporary=$(mktemp -d)
unzip -q "$archive" -d "$temporary"
while IFS= read -r file; do
	php -l "$file" >/dev/null
done < <(find "$temporary/ran-booster-bitbucket" -type f -name '*.php' -print)
