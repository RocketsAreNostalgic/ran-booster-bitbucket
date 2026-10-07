#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work_root=$(mktemp -d "${TMPDIR:-/tmp}/ran-bitbucket-coverage-test.XXXXXX")
trap 'rm -rf "$work_root"' EXIT
fixture="$work_root/repository with spaces"
mkdir -p "$fixture/scripts" "$fixture/src" "$fixture/views" "$fixture/tests/phpstan" "$fixture/vendor/bin" "$work_root/bin"
cp "$repo_root/scripts/check-product-php-coverage.php" "$fixture/scripts/"
ln -s "$repo_root/vendor/autoload.php" "$fixture/vendor/autoload.php"
ln -s "$repo_root/vendor/szepeviktor" "$fixture/vendor/szepeviktor"
ln -s "$repo_root/vendor/phpstan" "$fixture/vendor/phpstan"
ln -s "$repo_root/vendor/php-stubs" "$fixture/vendor/php-stubs"
cp "$repo_root/phpstan.neon.dist" "$repo_root/phpstan-development.neon.dist" "$repo_root/.phpcs.xml" "$fixture/"
cp "$repo_root/tests/phpstan/wordpress-http.stub" "$fixture/tests/phpstan/"
for file in autoload.php index.php ran-booster-bitbucket.php src/Sample.php views/guide.php tests/phpstan-bootstrap.php; do
	printf '<?php\n' > "$fixture/$file"
done

# Run the real Composer script definitions. The fixture's other check stages
# stand in for tools that are intentionally out of scope for this guard test.
php -r '
$source = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR)["scripts"];
if (!in_array("@check:coverage", $source["check"], true)
	|| !in_array("@test:coverage", $source["check"], true)
	|| !in_array("@analyze:development", $source["check:host"], true)
	|| !in_array("@check", $source["check:host"], true)) {
	throw new RuntimeException("Coverage guard is not in repository/host checks.");
}
echo json_encode(["scripts" => [
	"check:coverage" => $source["check:coverage"],
	"analyze" => $source["analyze"],
	"analyze:development" => $source["analyze:development"],
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

# Executable templates must fail before either checker silently skips their suffix.
for relative in src/coverage-template.phtml src/coverage-command tests/coverage-template.inc scripts/coverage-template.html views/coverage-template.htm src/coverage-template.tpl tests/coverage-template.custom; do
	for shape in html bom-long-echo; do
		php -r '$body = $argv[2] === "html" ? "<main>template</main><?php function ran_coverage_template(): int { return \"invalid\"; }" : "\xEF\xBB\xBF<main>" . str_repeat("x", 8192) . "</main><?= ran_missing_template_function(); ?>"; file_put_contents($argv[1], $body);' "$fixture/$relative" "$shape"
		if composer --working-dir="$fixture" check > "$work_root/template.log" 2>&1; then fail "executable template escaped: $relative/$shape"; fi
		grep -Eq 'Review (production|development) PHP outside lowercase .php' "$work_root/template.log" || fail 'template guard did not run'
		rm "$fixture/$relative"
	done
done
printf '<main>future executable template</main>\n' > "$fixture/src/coverage-template.phtml"
if composer --working-dir="$fixture" check > "$work_root/template.log" 2>&1; then fail 'phtml identity was silently ignored'; fi
grep -q 'Review production PHP outside lowercase .php' "$work_root/template.log" || fail 'phtml identity guard did not run'
rm "$fixture/src/coverage-template.phtml"
printf '# Example\n<main><?php example(); ?></main>\n' > "$fixture/coverage-example.md"
printf '{"example":"<main><?php example(); ?></main>"}\n' > "$fixture/coverage-example.json"
composer --working-dir="$fixture" check > "$work_root/inert.log" 2>&1 || fail 'inert documentation was classified as an executable template'
rm "$fixture/coverage-example.md" "$fixture/coverage-example.json"
# A .sh suffix alone is not an inert-language declaration; actual Bash fixture
# scripts deliberately contain quoted PHP and are not PHP source entrypoints.
printf '#!/usr/bin/env bash\nprintf '\''<main><?php fixture(); ?></main>'\''\n' > "$fixture/scripts/coverage-example.sh"
composer --working-dir="$fixture" check > "$work_root/bash-example.log" 2>&1 || fail 'declared Bash fixture text was classified as PHP'
sed -i '1d' "$fixture/scripts/coverage-example.sh"
if composer --working-dir="$fixture" check > "$work_root/bash-example.log" 2>&1; then fail 'undeclared .sh template escaped'; fi
grep -q 'Review development PHP outside lowercase .php' "$work_root/bash-example.log" || fail 'unknown-language body guard did not run'
rm "$fixture/scripts/coverage-example.sh"

# The real analyzer can return success without diagnostics when a bootstrap exits.
# The independent guard must reject every unreviewed effective identity first.
for profile in phpstan.neon.dist phpstan-development.neon.dist; do
	if [[ "$profile" == phpstan.neon.dist ]]; then diagnostic=src/Sample.php; else diagnostic=tests/BootstrapDiagnostic.php; fi
	printf '<?php\nfunction ran_booster_bitbucket_bootstrap_diagnostic(): int { return "invalid"; }\n' > "$fixture/$diagnostic"
	if (cd "$fixture" && php "$repo_root/vendor/bin/phpstan" analyse --configuration="$profile" --no-progress --error-format=json "$diagnostic") > "$work_root/bootstrap-before.json" 2> "$work_root/bootstrap-before.err"; then fail 'bootstrap baseline lost its real return diagnostic'; fi
	grep -q 'return.type' "$work_root/bootstrap-before.json" || fail 'bootstrap baseline did not diagnose invalid return'
	printf '<?php\nexit(0);\n' > "$fixture/vendor/coverage-early-exit.php"
	sed -i '/bootstrapFiles:/a\		- vendor/coverage-early-exit.php' "$fixture/$profile"
	if ! (cd "$fixture" && php "$repo_root/vendor/bin/phpstan" analyse --configuration="$profile" --no-progress --error-format=json "$diagnostic") > "$work_root/bootstrap-after.json" 2> "$work_root/bootstrap-after.err"; then fail 'early-exit control did not exercise successful analyzer termination'; fi
	[[ ! -s "$work_root/bootstrap-after.json" ]] || fail 'early-exit control unexpectedly produced an analysis report'
	if composer --working-dir="$fixture" check > "$work_root/bootstrap-guard.log" 2>&1; then fail 'early-success bootstrap escaped required coverage guard'; fi
	grep -q 'Review effective PHPStan bootstrap identities' "$work_root/bootstrap-guard.log" || fail 'effective bootstrap rejection did not run'
	cp "$repo_root/$profile" "$fixture/$profile"
	for mutation in duplicate replacement; do
		if [[ "$mutation" == duplicate ]]; then
			sed -i '/bootstrapFiles:/a\		- tests/phpstan-bootstrap.php' "$fixture/$profile"
		else
			sed -i 's#- tests/phpstan-bootstrap.php#- vendor/coverage-early-exit.php#' "$fixture/$profile"
		fi
		if composer --working-dir="$fixture" check > "$work_root/bootstrap-guard.log" 2>&1; then fail "bootstrap $mutation escaped"; fi
		grep -q 'Review effective PHPStan bootstrap identities' "$work_root/bootstrap-guard.log" || fail 'bootstrap identity/multiplicity rejection did not run'
		cp "$repo_root/$profile" "$fixture/$profile"
	done
	rm "$fixture/vendor/coverage-early-exit.php"
	if [[ "$diagnostic" == src/Sample.php ]]; then printf '<?php\n' > "$fixture/$diagnostic"; else rm "$fixture/$diagnostic"; fi
done
composer --working-dir="$fixture" check > "$work_root/restored-bootstrap.log" 2>&1 || fail 'reviewed bootstrap identities did not remain valid'
printf 'Template and bootstrap controls passed: executable bodies rejected, inert data admitted, actual early success and identity changes rejected.\n'

# WPCS 3 reads minimum_wp_version; the obsolete spelling only leaves its default.
for variant in active obsolete; do
	cp "$repo_root/.phpcs.xml" "$work_root/wp-$variant.xml"
	if [[ "$variant" == obsolete ]]; then sed -i 's/minimum_wp_version/minimum_supported_wp_version/' "$work_root/wp-$variant.xml"; fi
	printf '<?php\nwp_add_editor_classic_theme_styles();\n' | (cd "$repo_root" && php vendor/bin/phpcs --standard="$work_root/wp-$variant.xml" --stdin-path=src/CompatibilityProbe.php --report=json -q) > "$work_root/wp-$variant.json" || true
	php -r '$report=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);$types=[];foreach($report["files"] as $file){foreach($file["messages"] as $message){if($message["source"]==="WordPress.WP.DeprecatedFunctions.wp_add_editor_classic_theme_stylesFound"){$types[]=$message["type"];}}}if($types!==[$argv[2]]){throw new RuntimeException("WordPress floor did not enforce its actual diagnostic type.");}' "$work_root/wp-$variant.json" "$([[ "$variant" == active ]] && printf ERROR || printf WARNING)"
done
for mutation in obsolete missing duplicate; do
	case "$mutation" in
		obsolete) sed -i 's/minimum_wp_version/minimum_supported_wp_version/' "$fixture/.phpcs.xml" ;;
		missing) sed -i '/name="minimum_wp_version"/d' "$fixture/.phpcs.xml" ;;
		duplicate) sed -i '/name="minimum_wp_version"/a\	<config name="minimum_wp_version" value="7.0"/>' "$fixture/.phpcs.xml" ;;
	esac
	if composer --working-dir="$fixture" check > "$work_root/wp-guard.log" 2>&1; then fail "WordPress floor $mutation escaped"; fi
	grep -q 'Review PHPCS support or checker configuration' "$work_root/wp-guard.log" || fail 'WordPress floor rejection did not run'
	cp "$repo_root/.phpcs.xml" "$fixture/.phpcs.xml"
done
printf 'WordPress floor controls passed: active 7.0 error, obsolete-key warning, obsolete/missing/duplicate configs rejected.\n'

# The maintained transport declaration must not fall out through extensions.
sed -i 's/value="php,stub"/value="php"/' "$fixture/.phpcs.xml"
if composer --working-dir="$fixture" check > "$work_root/stub-extension.log" 2>&1; then
	fail 'HTTP stub escaped standards through narrowed extensions'
fi
grep -q 'Review PHPCS exclusions or command arguments' "$work_root/stub-extension.log" || fail 'stub extension control did not run'
cp "$repo_root/.phpcs.xml" "$fixture/.phpcs.xml"

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

cp "$work_root/composer.clean.json" "$fixture/composer.json"
for header in '<?php' '<?PHP' '<?='; do
    for path in extensionless-contract alternate-contract.inc; do
        printf '#!/usr/bin/env php\n%s\n' "$header" > "$fixture/$path"
        if composer --working-dir="$fixture" check > "$work_root/extensionless.log" 2>&1; then fail 'PHP header outside .php escaped'; fi
        grep -q 'Review production PHP outside lowercase .php' "$work_root/extensionless.log" || fail 'header control did not run'
        rm "$fixture/$path"
    done
done
# PHPStan directory discovery ignores dot files, despite lexical path coverage.
printf '<?php\n' > "$fixture/src/.hidden-contract.php"
if composer --working-dir="$fixture" check > "$work_root/effective.log" 2>&1; then fail 'lexical paths falsely certified hidden PHP'; fi
grep -q 'Effective PHPStan selection differs' "$work_root/effective.log" || fail 'effective selection control did not run'
rm "$fixture/src/.hidden-contract.php"
composer --working-dir="$fixture" check > "$work_root/restored.log" 2>&1 || fail 'restored effective scope failed'

sed -i '/stubFiles:/a\		- src/Sample.php' "$fixture/phpstan.neon.dist"
if composer --working-dir="$fixture" check > "$work_root/stub.log" 2>&1; then fail 'production reclassified as stub escaped'; fi
grep -q 'Effective PHPStan selection differs' "$work_root/stub.log" || fail 'stub filtering control did not run'
cp "$repo_root/phpstan.neon.dist" "$fixture/phpstan.neon.dist"

printf 'Coverage regression passed: valid scopes; root/case-variant PHP, narrowed paths, PHPCS exclusions, parsed NEON variants and command overrides rejected by required check.\n'

# Direct development selection and the >=5 floor must not regress.
sed -i 's/level: 5/level: 4/' "$fixture/phpstan-development.neon.dist"
if composer --working-dir="$fixture" check > "$work_root/development-level.log" 2>&1; then fail 'development level weakened'; fi
grep -q 'development level 5' "$work_root/development-level.log" || fail 'development floor control did not run'
cp "$repo_root/phpstan-development.neon.dist" "$fixture/phpstan-development.neon.dist"
printf '<?php\n' > "$fixture/tests/Future.php"
composer --working-dir="$fixture" check > "$work_root/future-development.log" 2>&1 || fail 'new development file not automatically covered'
sed -i '/analyseAndScan:/a\\	\	\	- tests/Future.php' "$fixture/phpstan-development.neon.dist"
if composer --working-dir="$fixture" check > "$work_root/development-excluded.log" 2>&1; then fail 'excluded development PHP escaped'; fi
grep -q 'Effective development PHPStan selection differs' "$work_root/development-excluded.log" || fail 'development scope control did not run'
cp "$repo_root/phpstan-development.neon.dist" "$fixture/phpstan-development.neon.dist"
sed -i '/parameters:/a\\	stubFiles:\n\	\	- tests/Future.php' "$fixture/phpstan-development.neon.dist"
if composer --working-dir="$fixture" check > "$work_root/development-stub.log" 2>&1; then fail 'development PHP reclassified as stub escaped'; fi
grep -q 'Effective development PHPStan selection differs' "$work_root/development-stub.log" || fail 'development stub control did not run'
printf 'Development coverage regression passed: future files, level floor, exclusions and CLI stub filtering.\n'
cp "$repo_root/phpstan-development.neon.dist" "$fixture/phpstan-development.neon.dist"
printf '<?PHP\n' > "$fixture/tests/hidden-contract.inc"
if composer --working-dir="$fixture" check > "$work_root/development-header.log" 2>&1; then fail 'development PHP header escaped extension control'; fi
grep -q 'Review development PHP outside lowercase .php' "$work_root/development-header.log" || fail 'development header control did not run'
rm "$fixture/tests/hidden-contract.inc"

# Real XML changes can keep discovery green while silencing prefix diagnostics.
for severity in 1 2 3 4; do
    cp "$repo_root/.phpcs.xml" "$fixture/.phpcs.xml"
    sed -i "s#</ruleset>#<rule ref=\"WordPress.NamingConventions.PrefixAllGlobals\"><severity>$severity</severity></rule></ruleset>#" "$fixture/.phpcs.xml"
    printf '<?php function unowned_probe() {}\n' | "$repo_root/vendor/bin/phpcs" --standard="$fixture/.phpcs.xml" --sniffs=WordPress.NamingConventions.PrefixAllGlobals --stdin-path="$fixture/tests/XmlProbe.php" -q - > "$work_root/xml-real.log" 2>&1 || fail 'low-severity XML no longer hides real diagnostic'
    if composer --working-dir="$fixture" check > "$work_root/xml-guard.log" 2>&1; then fail 'low-severity XML escaped guard'; fi
    grep -q 'Review PHPCS exclusions' "$work_root/xml-guard.log" || fail 'severity control failed for unrelated reason'
done
cp "$repo_root/.phpcs.xml" "$fixture/.phpcs.xml"
sed -i 's/value="ran_booster_bitbucket"/value="unowned"/' "$fixture/.phpcs.xml"
printf '<?php function unowned_probe() {}\n' | "$repo_root/vendor/bin/phpcs" --standard="$fixture/.phpcs.xml" --sniffs=WordPress.NamingConventions.PrefixAllGlobals --stdin-path="$fixture/tests/XmlProbe.php" -q - > "$work_root/xml-real.log" 2>&1 || fail 'prefix override no longer hides real diagnostic'
if composer --working-dir="$fixture" check > "$work_root/xml-guard.log" 2>&1; then fail 'prefix override escaped guard'; fi
grep -q 'Review PHPCS properties' "$work_root/xml-guard.log" || fail 'property control failed for unrelated reason'
cp "$repo_root/.phpcs.xml" "$fixture/.phpcs.xml"
printf '<?php function unowned_probe() {}\n' | "$repo_root/vendor/bin/phpcs" --standard="$fixture/.phpcs.xml" --sniffs=WordPress.NamingConventions.PrefixAllGlobals --stdin-path="$fixture/tests/XmlProbe.php" -q -s - > "$work_root/xml-real.log" 2>&1 && fail 'restored rules did not report unprefixed function'
grep -q 'NonPrefixedFunctionFound' "$work_root/xml-real.log" || fail 'restored actual prefix diagnostic missing'
composer --working-dir="$fixture" check > "$work_root/xml-restored.log" 2>&1 || fail 'restored XML guard failed'
printf 'XML controls passed: severity 1-4 and prefix-property weakening are rejected.\n'

# The canonical checker must retain diagnostics for newly introduced source files.
# Do not use --sniffs: that CLI override can reactivate conditionally disabled rules.
json_probe() {
    printf '<?php json_encode( array() );\n' | "$repo_root/vendor/bin/phpcs" --standard="$fixture/.phpcs.xml" --stdin-path="$fixture/src/unreviewed-future.php" -q --report=json - > "$work_root/selector-real.json" || :
    php -r '$v = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); foreach ($v["files"] as $file) { foreach ($file["messages"] as $message) { if ($message["source"] === "WordPress.WP.AlternativeFunctions.json_encode_json_encode") { exit(0); } } } exit(1);' "$work_root/selector-real.json" && return 0
    local status=$?
    [[ "$status" -eq 1 ]] || fail 'actual checker did not produce a valid JSON diagnostic report'
    return 1
}
json_probe || fail 'canonical JSON diagnostic missing before selector controls'
for selector in cbf-only phpcs-false include-default include-absolute include-relative; do
    cp "$repo_root/.phpcs.xml" "$fixture/.phpcs.xml"
    php -r '
        $path = $argv[1];
        $selector = $argv[2];
        $rule = "<rule ref=\"RANWordPressPlugin\"";
        if ($selector === "cbf-only") {
            $replacement = $rule . " phpcbf-only=\"true\"/>";
        } elseif ($selector === "phpcs-false") {
            $replacement = $rule . " phpcs-only=\"false\"/>";
        } else {
            $type = substr($selector, 8);
            $attribute = $type === "default" ? "" : " type=\"$type\"";
            $replacement = $rule . "><include-pattern$attribute>^(?!*unreviewed-future[.]php)</include-pattern></rule>";
        }
        $xml = str_replace($rule . "/>", $replacement, file_get_contents($path), $count);
        if ($count !== 1) { exit(1); }
        file_put_contents($path, $xml);
    ' "$fixture/.phpcs.xml" "$selector" || fail 'selector mutation did not alter exactly the existing rule'
    if json_probe; then fail "$selector no longer hides the actual JSON diagnostic"; fi
    if composer --working-dir="$fixture" check > "$work_root/selector-guard.log" 2>&1; then fail "$selector escaped the required guard"; fi
    grep -q 'Review PHPCS exclusions' "$work_root/selector-guard.log" || fail "$selector failed for an unrelated reason"
done
cp "$repo_root/.phpcs.xml" "$fixture/.phpcs.xml"
json_probe || fail 'restored actual JSON diagnostic missing'
composer --working-dir="$fixture" check > "$work_root/selector-restored.log" 2>&1 || fail 'restored selector guard failed'
printf 'Selector controls passed: command-conditional rules and default/absolute/relative include patterns cannot hide future-file diagnostics.\n'
