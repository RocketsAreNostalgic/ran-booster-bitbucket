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
    if (cd "$work_root" && php vendor/bin/phpstan analyse -c phpstan-development.neon.dist --no-progress --error-format=json) > "$work_root/result.json"; then fail "new PHP escaped level 8: $relative"; fi
    grep -q 'return.type' "$work_root/result.json" || fail 'future source lacked the expected return diagnostic'
    rm "$work_root/$relative"
    if [[ -f "$work_root/saved-contract" ]]; then mv "$work_root/saved-contract" "$work_root/$relative"; fi
done
# Exercise all automatically included roles in one Level 8-only nullable control.
nullable_files=(tests/FutureAnalysis.php scripts/future-analysis.php tests/phpstan/wordpress-http.stub)
cp "$work_root/tests/phpstan/wordpress-http.stub" "$work_root/saved-contract"
for index in "${!nullable_files[@]}"; do
    printf '<?php\nfunction ran_booster_bitbucket_nullable_probe_%s(?string $value): string { return strtolower($value); }\n' "$index" > "$work_root/${nullable_files[$index]}"
done
if (cd "$work_root" && php vendor/bin/phpstan analyse -c phpstan-development.neon.dist --no-progress --error-format=json) > "$work_root/result.json"; then fail 'nullable PHP escaped level 8'; fi
php -r '
$result = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
foreach (array_slice($argv, 2) as $file) {
    $messages = $result["files"][$file]["messages"] ?? array();
    if (!in_array("argument.type", array_column($messages, "identifier"), true)) {
        fwrite(STDERR, "Nullable source lacked the expected argument diagnostic: $file\n");
        exit(1);
    }
}
' "$work_root/result.json" "${nullable_files[@]/#/$work_root/}" || fail 'nullable role diagnostics missing'
if ! (cd "$work_root" && php vendor/bin/phpstan analyse -c phpstan-development.neon.dist --level=7 --no-progress --error-format=json) > "$work_root/level-seven.json"; then fail 'nullable control is not specific to level 8'; fi
rm "$work_root/tests/FutureAnalysis.php" "$work_root/scripts/future-analysis.php"
mv "$work_root/saved-contract" "$work_root/tests/phpstan/wordpress-http.stub"
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
