#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work_root=$(mktemp -d "${TMPDIR:-/tmp}/ran-bitbucket-coverage-test.XXXXXX")
trap 'rm -rf "$work_root"' EXIT
fixture="$work_root/repository with spaces"
mkdir -p "$fixture/scripts" "$fixture/src" "$fixture/views" "$fixture/tests" "$fixture/vendor/bin" "$work_root/bin"
cp "$repo_root/scripts/check-product-php-coverage.php" "$fixture/scripts/"
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
	"test:coverage" => "php -r '\''exit(0);'\''",
	"check" => $source["check"],
]], JSON_THROW_ON_ERROR);
' "$repo_root/composer.json" > "$fixture/composer.json"
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

sed -i 's/--configuration=phpstan.neon.dist/--configuration=other.neon/' "$fixture/composer.json"
if composer --working-dir="$fixture" check > "$work_root/command.log" 2>&1; then
	fail 'analysis command config drift escaped the required check'
fi
grep -q 'actual Composer analyze configuration' "$work_root/command.log" || fail 'command control did not run'

printf 'Coverage regression passed: valid scopes; new root, narrowed analysis/standards, PHPCS exclusion and command drift rejected by required check.\n'
