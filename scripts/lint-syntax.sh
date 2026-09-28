#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$repo_root"

file_list=$(mktemp "${TMPDIR:-/tmp}/ran-bitbucket-php-files.XXXXXX")
trap 'rm -f "$file_list"' EXIT

# Finish discovery successfully before parsing; a pipeline can hide find failures.
find . -path ./vendor -prune -o -path ./.phpstan -prune -o -path ./.phpunit.cache -prune \
	-o -type f -name '*.php' -print0 > "$file_list"
if [[ ! -s "$file_list" ]]; then
	echo 'PHP syntax check found no source files.' >&2
	exit 1
fi

while IFS= read -r -d '' file; do
	php -l "$file"
done < "$file_list"
