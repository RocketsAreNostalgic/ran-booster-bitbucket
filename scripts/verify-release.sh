#!/usr/bin/env bash

set -euo pipefail

archive=${1:?archive is required}
unzip -tqq "$archive"
temporary=$(mktemp -d)
trap 'rm -rf "$temporary"' EXIT HUP INT TERM
unzip -q "$archive" -d "$temporary"
while IFS= read -r file; do
	php -l "$file" >/dev/null
done < <(find "$temporary/ran-booster-bitbucket" -type f -name '*.php' -print)
