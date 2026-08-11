#!/usr/bin/env bash

set -euo pipefail

archive=${1:?archive is required}
source_commit=${2:?full source commit is required}
root=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)

exec php "$root/scripts/verify-release.php" "$archive" "$source_commit"
