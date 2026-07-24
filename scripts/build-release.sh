#!/usr/bin/env bash

set -euo pipefail

root=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)
cd "$root"

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
	echo "Refusing to build from a tracked dirty worktree." >&2
	exit 1
fi

version=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p' ran-booster-bitbucket.php)
test -n "$version"
readme_version=$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p' readme.txt)
composer_version=$(php -r '$manifest = json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR); echo $manifest["version"] ?? "";')

if [ "$version" != "$readme_version" ] || [ "$version" != "$composer_version" ]; then
	echo "Plugin header, readme stable tag, and Composer version must match." >&2
	exit 1
fi

files=()
while IFS= read -r file; do
	case "$file" in
		''|'#'*) continue ;;
	esac
	if ! git cat-file -e "HEAD:$file" 2>/dev/null; then
		echo "Release allowlist entry is missing from HEAD: $file" >&2
		exit 1
	fi
	files+=( "$file" )
done < release-files.txt

mkdir -p build
archive="build/ran-booster-bitbucket-$version.zip"
git archive --format=zip --prefix=ran-booster-bitbucket/ --output="$archive" HEAD -- "${files[@]}"
shasum -a 256 "$archive" > "$archive.sha256"
"$(dirname "$0")/verify-release.sh" "$archive"
