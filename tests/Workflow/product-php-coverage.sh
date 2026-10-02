#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work_root=$(mktemp -d "${TMPDIR:-/tmp}/ran-bitbucket-coverage-test.XXXXXX")
trap 'rm -rf "$work_root"' EXIT
fixture="$work_root/repository with spaces"
mkdir -p "$fixture/scripts" "$fixture/src" "$fixture/views" "$fixture/tests" "$fixture/vendor/bin" "$work_root/bin"
cp "$repo_root/scripts/check-product-php-coverage.php" "$fixture/scripts/"
ln -s "$repo_root/vendor/autoload.php" "$fixture/vendor/autoload.php"
cp "$repo_root/phpstan.neon.dist" "$repo_root/.phpcs.xml" "$fixture/"
for file in autoload.php index.php ran-booster-bitbucket.php src/Sample.php views/guide.php; do
	printf '<?php\n' > "$fixture/$file"
done

# Run the real Composer script definitions. The fixture's other check stages
# stand in for tools that are intentionally out of scope for this guard test.
php -r '
$source = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR)["scripts"];
if (!in_array("@check:coverage", $source["check"], true)
	|| !in_array("@test:coverage", $source["check"], true)
	|| !in_array("@check", $source["check:host"], true)) {
	throw new RuntimeException("Coverage guard is not in repository/host checks.");
}
echo json_encode(["scripts" => [
	"check:coverage" => $source["check:coverage"],
	"analyze" => $source["analyze"],
	"standards" => $source["standards"],
	"standards:fix" => $source["standards:fix"],
	"lint:syntax" => "php -r '\''exit(0);'\''",
	"test:syntax" => "php -r '\''exit(0);'\''",
	"test:naming" => "php -r '\''exit(0);'\''",
	"test:coverage" => "php -r '\''exit(0);'\''",
	"check" => $source["check"],
]], JSON_THROW_ON_ERROR);
' "$repo_root/composer.json" > "$fixture/composer.json"
cp "$fixture/composer.json" "$work_root/composer.clean.json"
printf '#!/usr/bin/env bash\nexit 0\n' > "$fixture/vendor/bin/phpcs"
chmod +x "$fixture/vendor/bin/phpcs"

fail() { printf 'coverage regression: %s\n' "$*" >&2; exit 1; }
composer --working-dir="$fixture" check > "$work_root/clean.log" 2>&1 || fail 'valid direct scopes failed'

mkdir "$fixture/new product"
printf '<?php\n' > "$fixture/new product/plugin.php"
if composer --working-dir="$fixture" check > "$work_root/new-root.log" 2>&1; then
	fail 'new root product PHP escaped the required check'
fi
grep -q 'PHPStan does not directly select maintained PHP: new product/plugin.php' "$work_root/new-root.log" || fail 'root control did not run'
rm -r "$fixture/new product"

printf '<?php\n' > "$fixture/src/Skipped.PHP"
if composer --working-dir="$fixture" check > "$work_root/uppercase.log" 2>&1; then
	fail 'case-variant PHP extension escaped the required check'
fi
grep -q 'Review unsupported product PHP extension' "$work_root/uppercase.log" || fail 'uppercase control did not run'
rm "$fixture/src/Skipped.PHP"

printf '<?php\n' > "$fixture/src/Other.php"
sed -i 's/\t\t- src$/\t\t- src\/Sample.php/' "$fixture/phpstan.neon.dist"
if composer --working-dir="$fixture" check > "$work_root/narrowed.log" 2>&1; then
	fail 'narrowed analysis path escaped the required check'
fi
grep -q 'PHPStan does not directly select maintained PHP: src/Other.php' "$work_root/narrowed.log" || fail 'narrowed control did not run'
cp "$repo_root/phpstan.neon.dist" "$fixture/phpstan.neon.dist"
rm "$fixture/src/Other.php"

sed -i 's#<file>src</file>#<file>src/Sample.php</file>#' "$fixture/.phpcs.xml"
printf '<?php\n' > "$fixture/src/Other.php"
if composer --working-dir="$fixture" check > "$work_root/standards.log" 2>&1; then
	fail 'narrowed standards path escaped the required check'
fi
grep -q 'PHPCS/PHPCBF does not directly select maintained PHP: src/Other.php' "$work_root/standards.log" || fail 'standards control did not run'
cp "$repo_root/.phpcs.xml" "$fixture/.phpcs.xml"
rm "$fixture/src/Other.php"

sed -i '/<file>src<\/file>/a\	<exclude-pattern>*/src/*</exclude-pattern>' "$fixture/.phpcs.xml"
if composer --working-dir="$fixture" check > "$work_root/exclusion.log" 2>&1; then
	fail 'standards exclusion escaped the required check'
fi
grep -q 'Review PHPCS exclusions' "$work_root/exclusion.log" || fail 'exclusion control did not run'
cp "$repo_root/.phpcs.xml" "$fixture/.phpcs.xml"

sed -i '/<file>src<\/file>/a\	<arg name="ignore" value="src/*"/>' "$fixture/.phpcs.xml"
if composer --working-dir="$fixture" check > "$work_root/ignore.log" 2>&1; then
	fail 'PHPCS ignore argument escaped the required check'
fi
grep -q 'Review PHPCS exclusions' "$work_root/ignore.log" || fail 'PHPCS ignore control did not run'
cp "$repo_root/.phpcs.xml" "$fixture/.phpcs.xml"

printf '\tfileExtensions:\n\t\t- inc\n' >> "$fixture/phpstan.neon.dist"
if composer --working-dir="$fixture" check > "$work_root/extensions.log" 2>&1; then
	fail 'non-PHP PHPStan extension list escaped the required check'
fi
grep -q 'PHPStan file extensions omit PHP' "$work_root/extensions.log" || fail 'extension control did not run'
cp "$repo_root/phpstan.neon.dist" "$fixture/phpstan.neon.dist"
printf '\tfileExtensions:\n\t\t- php\n' >> "$fixture/phpstan.neon.dist"
composer --working-dir="$fixture" check > "$work_root/php-extension.log" 2>&1 || fail 'explicit PHP extension failed'
cp "$repo_root/phpstan.neon.dist" "$fixture/phpstan.neon.dist"

printf '\t"excludePaths":\n\t\t- src\n' >> "$fixture/phpstan.neon.dist"
if composer --working-dir="$fixture" check > "$work_root/quoted-exclusion.log" 2>&1; then
	fail 'quoted PHPStan exclusion escaped the required check'
fi
grep -q 'Cannot certify PHPStan scope with excluded paths' "$work_root/quoted-exclusion.log" || fail 'quoted exclusion control did not run'
cp "$repo_root/phpstan.neon.dist" "$fixture/phpstan.neon.dist"

printf '\texcludePaths!:\n\t\t- src\n' >> "$fixture/phpstan.neon.dist"
if composer --working-dir="$fixture" check > "$work_root/merge-exclusion.log" 2>&1; then
	fail 'NEON merge-control exclusion escaped the required check'
fi
grep -q 'Cannot certify PHPStan scope with excluded paths' "$work_root/merge-exclusion.log" || fail 'merge exclusion control did not run'
cp "$repo_root/phpstan.neon.dist" "$fixture/phpstan.neon.dist"

mkdir "$fixture/addon" "$fixture/addon # legacy"
printf '<?php\n' > "$fixture/addon # legacy/plugin.php"
sed -i '/\t\t- index.php/a\		- addon # legacy' "$fixture/phpstan.neon.dist"
if composer --working-dir="$fixture" check > "$work_root/comment-path.log" 2>&1; then
	fail 'unparsed NEON comment in source path escaped the required check'
fi
grep -q 'PHPStan does not directly select maintained PHP: addon # legacy/plugin.php' "$work_root/comment-path.log" || fail 'comment path control did not run'
cp "$repo_root/phpstan.neon.dist" "$fixture/phpstan.neon.dist"
rm -r "$fixture/addon" "$fixture/addon # legacy"

sed -i 's/--configuration=phpstan.neon.dist/--configuration=phpstan.neon.dist-narrow/' "$fixture/composer.json"
if composer --working-dir="$fixture" check > "$work_root/command.log" 2>&1; then
	fail 'similar analysis config name escaped the required check'
fi
grep -q 'actual Composer analyze configuration' "$work_root/command.log" || fail 'command control did not run'
cp "$work_root/composer.clean.json" "$fixture/composer.json"
sed -i 's/--standard=.phpcs.xml/--standard=.phpcs.xml-narrow/g' "$fixture/composer.json"
if composer --working-dir="$fixture" check > "$work_root/standards-command.log" 2>&1; then
	fail 'similar standards config name escaped the required check'
fi
grep -q 'actual Composer standards configuration' "$work_root/standards-command.log" || fail 'standards command control did not run'
cp "$work_root/composer.clean.json" "$fixture/composer.json"
php -r '$f = $argv[1]; $v = json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR); $v["scripts"]["analyze"] .= " src"; file_put_contents($f, json_encode($v, JSON_THROW_ON_ERROR));' "$fixture/composer.json"
if composer --working-dir="$fixture" check > "$work_root/positional-path.log" 2>&1; then
	fail 'positional PHPStan path escaped the required check'
fi
grep -q 'actual Composer analyze configuration' "$work_root/positional-path.log" || fail 'positional path control did not run'

printf 'Coverage regression passed: valid scopes; root/case-variant PHP, narrowed paths, PHPCS exclusions, parsed NEON variants and command overrides rejected by required check.\n'
