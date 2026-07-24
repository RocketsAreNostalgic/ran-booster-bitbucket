#!/usr/bin/env bash

set -euo pipefail

root=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)
cd "$root"
version=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p' ran-booster-bitbucket.php)
test -n "$version"
mkdir -p build
archive="build/ran-booster-bitbucket-$version.zip"
git archive --format=zip --prefix=ran-booster-bitbucket/ --output="$archive" HEAD -- $(grep -Ev '^(#|$)' release-files.txt)
shasum -a 256 "$archive" > "$archive.sha256"
"$(dirname "$0")/verify-release.sh" "$archive"
