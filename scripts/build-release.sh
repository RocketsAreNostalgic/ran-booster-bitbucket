#!/usr/bin/env bash

set -euo pipefail

# ZIP stores timezone-naive timestamps, so fix the build zone for reproducibility.
export TZ=UTC

root=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)
cd "$root"

source_commit=${1:?full source commit is required}
if [[ ! "$source_commit" =~ ^[0-9a-f]{40}$ ]] \
	|| ! git cat-file -e "${source_commit}^{commit}" 2>/dev/null \
	|| [[ "$(git rev-parse "${source_commit}^{commit}")" != "$source_commit" ]]; then
	echo "Source commit must be an existing full commit ID." >&2
	exit 1
fi

version=$(git show "${source_commit}:ran-booster-bitbucket.php" | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p')
test -n "$version"
readme_version=$(git show "${source_commit}:readme.txt" | sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p')

if [ "$version" != "$readme_version" ]; then
	echo "Plugin header and readme stable tag must match." >&2
	exit 1
fi

files=()
while IFS= read -r file; do
	case "$file" in
		''|'#'*) continue ;;
	esac
	if ! git cat-file -e "$source_commit:$file" 2>/dev/null; then
		echo "Release allowlist entry is missing from the source commit: $file" >&2
		exit 1
	fi
	files+=( "$file" )
done < <(git show "${source_commit}:release-files.txt")

mkdir -p build
archive="build/ran-booster-bitbucket-$version.zip"
git archive --format=zip --prefix=ran-booster-bitbucket/ --output="$archive" "$source_commit" -- "${files[@]}"
( cd "$(dirname "$archive")" && shasum -a 256 "$(basename "$archive")" ) > "$archive.sha256"
"$(dirname "$0")/verify-release.sh" "$archive" "$source_commit"
