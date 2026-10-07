#!/usr/bin/env bash
set -euo pipefail
repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work_root=$(mktemp -d)
trap 'rm -rf "$work_root"' EXIT
cp -R "$repo_root/src" "$repo_root/views" "$repo_root/tests" "$repo_root/scripts" "$work_root/"
cp "$repo_root/composer.json" "$repo_root/autoload.php" "$repo_root/index.php" "$repo_root/ran-booster-bitbucket.php" "$repo_root/phpstan-development.neon.dist" "$work_root/"
ln -s "$repo_root/vendor" "$work_root/vendor"
fail() { printf 'development analysis regression: %s\n' "$*" >&2; exit 1; }
for relative in tests/FutureAnalysis.php scripts/future-analysis.php tests/phpstan/wordpress-http.stub; do
    if [[ -f "$work_root/$relative" ]]; then cp "$work_root/$relative" "$work_root/saved-contract"; fi
    printf '<?php\nfunction ran_booster_bitbucket_future_probe(): int { return "invalid"; }\n' > "$work_root/$relative"
    if (cd "$work_root" && php vendor/bin/phpstan analyse -c phpstan-development.neon.dist --no-progress --error-format=json) > "$work_root/result.json"; then fail "new PHP escaped level 5: $relative"; fi
    grep -q 'return.type' "$work_root/result.json" || fail 'future source lacked the expected return diagnostic'
    rm "$work_root/$relative"
    if [[ -f "$work_root/saved-contract" ]]; then mv "$work_root/saved-contract" "$work_root/$relative"; fi
done
# Existing exact ignores may not cover an immediately following occurrence.
sed -i '/^$config = /a new PHPStan\\DependencyInjection\\NeonAdapter( array() );' "$work_root/scripts/check-product-php-coverage.php"
if (cd "$work_root" && php vendor/bin/phpstan analyse -c phpstan-development.neon.dist --no-progress --error-format=json) > "$work_root/result.json"; then fail 'adjacent internal API call escaped'; fi
grep -q 'phpstanApi.constructor' "$work_root/result.json" || fail 'adjacent API diagnostic missing'
printf 'Development analysis controls passed: future tests/scripts and adjacent API occurrence.\n'
cp "$repo_root/scripts/check-product-php-coverage.php" "$work_root/scripts/check-product-php-coverage.php"
sed -i '/self::assertIsString( $json );/a self::assertIsString( $json );' "$work_root/tests/RepositoryProvider/BitbucketWebhookNormalizerTest.php"
if (cd "$work_root" && php vendor/bin/phpstan analyse -c phpstan-development.neon.dist --no-progress --error-format=json) > "$work_root/result.json"; then fail 'adjacent JSON assertion escaped'; fi
grep -q 'staticMethod.alreadyNarrowedType' "$work_root/result.json" || fail 'adjacent JSON assertion diagnostic missing'
printf 'Exact JSON assertion exemption leaves the adjacent assertion checked.\n'
