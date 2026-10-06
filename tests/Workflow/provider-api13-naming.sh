#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work_root=$(mktemp -d "${TMPDIR:-/tmp}/ran-bitbucket-api13-naming.XXXXXX")
trap 'rm -rf "$work_root"' EXIT
cd "$repo_root"

# Use the actual repository scope and activated rule. Ignore annotations here so
# an obsolete API-12 exception cannot conceal a reverted declaration.
vendor/bin/phpcs --standard=.phpcs.xml --sniffs=RANOwnedMethods.NamingConventions.ValidMethodName --ignore-annotations --no-colors -q --report=json src ran-booster-bitbucket.php autoload.php index.php views > "$work_root/positive.json"

# Mutate every migrated declaration in disposable copies. This exercises the
# real ruleset against implementing classes without loading candidate Core.
php /dev/stdin "$repo_root" "$work_root" <<'PHP'
<?php
$mapping = [
    'BitbucketProvider' => [
        'get_metadata', 'get_provider_diagnostics', 'get_credential_policy',
        'get_webhook_policy', 'diagnose_webhook_readiness', 'validate_credential',
        'browse_repositories', 'get_public_repository_browse_metadata',
        'resolve_repository', 'prepare_archive', 'normalize_webhook',
        'repository_webhook_settings_url',
    ],
    'BitbucketCredentialPolicy' => [
        'get_provider', 'normalize_credential', 'get_constant_names', 'credential_from_constants',
    ],
    'BitbucketCredentialValidator' => ['validate_credential'],
    'BitbucketWebhookNormalizer' => [
        'get_webhook_policy', 'diagnose_webhook_readiness', 'normalize_webhook',
    ],
    'BitbucketWebhookPolicy' => [
        'get_provider', 'get_retained_headers', 'get_signature_header', 'normalize_webhook',
        'get_constant_names', 'webhook_from_constants', 'authorize_webhook', 'repository_target_matches',
    ],
];
$expected = [];
foreach ($mapping as $class => $names) {
    $contents = file_get_contents($argv[1] . '/src/Bitbucket/' . $class . '.php');
    foreach ($names as $name) {
        $old = preg_replace_callback('/_([a-z])/', static fn ($match) => strtoupper($match[1]), $name);
        $contents = preg_replace('/\bfunction ' . preg_quote($name, '/') . '\s*\(/', 'function ' . $old . '(', $contents, -1, $count);
        if ($count !== 1) {
            throw new RuntimeException('Expected one API-13 declaration: ' . $class . '::' . $name);
        }
        $expected[$class . '.php'][] = $old;
    }
    file_put_contents($argv[2] . '/' . $class . '.php', $contents);
}
file_put_contents($argv[2] . '/expected.json', json_encode($expected, JSON_THROW_ON_ERROR));
PHP

set +e
vendor/bin/phpcs --standard=.phpcs.xml --sniffs=RANOwnedMethods.NamingConventions.ValidMethodName --no-colors -q --report=json "$work_root" > "$work_root/negative.json"
status=$?
set -e
if [[ "$status" != 1 ]]; then
    printf 'Expected blocking naming errors, got exit %s.\n' "$status" >&2
    cat "$work_root/negative.json" >&2
    exit 1
fi

php /dev/stdin "$work_root" <<'PHP'
<?php
$expected = json_decode(file_get_contents($argv[1] . '/expected.json'), true, 512, JSON_THROW_ON_ERROR);
$report = json_decode(file_get_contents($argv[1] . '/negative.json'), true, 512, JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['files'] as $file => $details) {
    foreach ($details['messages'] as $message) {
        if ($message['source'] !== 'RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase'
            || $message['type'] !== 'ERROR' || $message['fixable']
            || !preg_match('/Owned method "([a-zA-Z]+)"/', $message['message'], $matches)) {
            throw new RuntimeException('Unexpected naming diagnostic: ' . json_encode($message));
        }
        $actual[basename($file)][] = $matches[1];
    }
}
foreach ($expected as &$names) {
    sort($names);
}
unset($names);
foreach ($actual as &$names) {
    sort($names);
}
unset($names);
ksort($expected);
ksort($actual);
if ($expected !== $actual || array_sum(array_map('count', $actual)) !== 28) {
    throw new RuntimeException('Every API-13 declaration must reject its API-12 spelling: ' . json_encode($actual));
}
echo "Provider API-13 naming controls passed: maintained product declarations comply; all 28 reverted declarations are blocking errors.\n";
PHP

# Qualify the same whole-maintained-PHP profile through its actual Composer commands.
fixture="$work_root/development scope"
mkdir "$fixture"
git ls-files -z > "$work_root/tracked"
tar --null -T "$work_root/tracked" -cf - | tar -C "$fixture" -xf -
ln -s "$repo_root/vendor" "$fixture/vendor"
run() { composer --no-interaction --no-plugins --working-dir="$fixture" "$1" > "$work_root/command.log" 2>&1; }
fail() { printf 'development standards: %s\n' "$*" >&2; cat "$work_root/command.log" >&2; exit 1; }
snapshot() { (cd "$fixture" && xargs -0 sha256sum < "$work_root/tracked") > "$1"; }
run standards || fail 'clean maintained PHP did not pass'
snapshot "$work_root/before"
for pass in 1 2; do
	status=0
	run standards:fix || status=$?
	(( status <= 1 )) || fail 'fixer failed'
	snapshot "$work_root/after"
	cmp -s "$work_root/before" "$work_root/after" || fail 'clean fixer changed tracked bytes'
done

# Ordinary PHPCS suppression syntax must not bypass the independent coverage guard.
for suppression in '<rule ref="RANWordPressPlugin"><exclude name="WordPress.PHP.YodaConditions"/></rule>' '<rule ref="WordPress.PHP.YodaConditions"><severity>0</severity></rule>'; do
	sed '/<\/ruleset>/i\'"$suppression" "$repo_root/.phpcs.xml" > "$fixture/.phpcs.xml"
	if run check:coverage; then fail 'ruleset diagnostic suppression escaped the guard'; fi
	grep -q 'Review PHPCS exclusions' "$work_root/command.log" || fail 'ruleset suppression failed for an unrelated reason'
done
cp "$repo_root/.phpcs.xml" "$fixture/.phpcs.xml"

# Ruleset command arguments must not disable or select away required sniffs.
for argument in '<arg name="exclude" value="RANOwnedMethods.NamingConventions.ValidMethodName"/>' '<arg name="sniffs" value="WordPress.PHP.YodaConditions"/>'; do
	printf '<ruleset><rule ref="RANOwnedMethods"/>%s</ruleset>\n' "$argument" > "$work_root/argument.xml"
	printf '<?php class Probe { public function badName() {} }' | "$repo_root/vendor/bin/phpcs" --standard="$work_root/argument.xml" -q - > "$work_root/command.log" 2>&1 || fail 'argument no longer hides the locked checker violation'
	sed '/<\/ruleset>/i\'"$argument" "$repo_root/.phpcs.xml" > "$fixture/.phpcs.xml"
	if run check:coverage; then fail 'ruleset command argument escaped the guard'; fi
	grep -q 'Review PHPCS exclusions or command arguments' "$work_root/command.log" || fail 'argument failed for an unrelated reason'
done
cp "$repo_root/.phpcs.xml" "$fixture/.phpcs.xml"

# Development-role exceptions must never leak to same-named product subdirectories.
for directory in tests scripts; do
	mkdir -p "$fixture/src/$directory"
	printf '<?php\nnamespace UnprefixedProbe;\n$unprefixed_probe = 1;\n' > "$fixture/src/$directory/PrefixProbe.php"
	if run standards; then fail 'product subdirectory escaped prefix checking'; fi
	"$repo_root/vendor/bin/phpcs" --standard="$fixture/.phpcs.xml" -s --report=full "$fixture/src/$directory/PrefixProbe.php" >> "$work_root/command.log" 2>&1 || true
	grep -q 'NonPrefixedNamespaceFound' "$work_root/command.log" || fail 'product namespace prefix diagnostic was suppressed'
	grep -q 'NonPrefixedVariableFound' "$work_root/command.log" || fail 'product variable prefix diagnostic was suppressed'
	rm "$fixture/src/$directory/PrefixProbe.php"
	rmdir "$fixture/src/$directory"
done

printf '<?php\nnamespace Tests;\nclass DevelopmentProbe extends \\PHPUnit\\Framework\\TestCase { public function ownedBadName(): void {} }\n' > "$fixture/tests/DevelopmentProbe.php"
if run standards; then fail 'new inherited owned test method escaped the real checker'; fi
"$repo_root/vendor/bin/phpcs" --standard="$fixture/.phpcs.xml" -s --report=full "$fixture/tests/DevelopmentProbe.php" >> "$work_root/command.log" 2>&1 || true
grep -q 'RANOwnedMethods' "$work_root/command.log" || fail 'new test failed for an unrelated reason'
grep -q 'NonPrefixedNamespaceFound' "$work_root/command.log" || fail 'owned test namespace escaped the real checker'
for directive in 'phpcs:set' '@codingStandardsChangeSetting'; do
	printf '<?php\n// %s WordPress.NamingConventions.PrefixAllGlobals prefixes unowned\nfunction unowned_probe() {}\n' "$directive" > "$fixture/tests/DevelopmentProbe.php"
	"$repo_root/vendor/bin/phpcs" --standard="$fixture/.phpcs.xml" --sniffs=WordPress.NamingConventions.PrefixAllGlobals -q "$fixture/tests/DevelopmentProbe.php" > "$work_root/command.log" 2>&1 || fail 'inline property control no longer suppresses the real checker'
	if run check:coverage > "$work_root/command.log" 2>&1; then fail 'inline property change escaped token guard'; fi
	grep -qi 'blanket PHPCS suppression' "$work_root/command.log" || fail 'inline property control failed for an unrelated reason'
done
rm "$fixture/tests/DevelopmentProbe.php"
printf '<?php\n$ownedBadVariable = 1;\n' > "$fixture/scripts/DevelopmentProbe.php"
if run standards; then fail 'new CLI variable escaped the real checker'; fi
"$repo_root/vendor/bin/phpcs" --standard="$fixture/.phpcs.xml" -s --report=full "$fixture/scripts/DevelopmentProbe.php" >> "$work_root/command.log" 2>&1 || true
grep -q 'VariableNotSnakeCase' "$work_root/command.log" || fail 'new CLI variable failed for an unrelated reason'
rm "$fixture/scripts/DevelopmentProbe.php"

for path in scripts/verify-release.php tests/bootstrap.php; do
	cp "$fixture/$path" "$work_root/protected.php"
	printf '\nfunction unprefixed_future_declaration() {}\n' >> "$fixture/$path"
	"$repo_root/vendor/bin/phpcs" --standard="$fixture/.phpcs.xml" -s "$fixture/$path" > "$work_root/command.log" 2>&1 && fail 'new declaration inherited process-variable waiver'
	grep -q 'NonPrefixedFunctionFound' "$work_root/command.log" || fail 'new declaration prefix diagnostic disappeared'
	cp "$work_root/protected.php" "$fixture/$path"
done

# The coverage guard must reject narrowing development paths as well as production.
sed -i '/<file>tests<\/file>/d' "$fixture/.phpcs.xml"
if run check:coverage; then fail 'development selection removal escaped coverage'; fi
grep -q 'PHPCS/PHPCBF does not directly select maintained PHP: tests/' "$work_root/command.log" || fail 'development removal failed for an unrelated reason'
cp .phpcs.xml "$fixture/.phpcs.xml"
for annotation in '// phpcs:ignoreFile' '// phpcs:disable' '/* phpcs:disable */' '/** phpcs:disable */' '// PHPCS:DISABLE' '// PHPCS:IGNOREfileXYZ' '// phpcs:ignore RANOwnedMethods -- Broad standard' '// phpcs:disable RANOwnedMethods.NamingConventions -- Broad category' '// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName -- Broad sniff' '// phpcs:disable RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Persistent method waiver' '// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase' '// @codingStandardsIgnoreStart' '// @codingStandardsIgnoreFile' '// @codingStandardsIgnoreLine'; do
	printf '<?php\n%s\nclass NamingProbe { public function hiddenBadName() {} }\n' "$annotation" > "$fixture/tests/DevelopmentProbe.php"
	"$repo_root/vendor/bin/phpcs" --standard=RANOwnedMethods -q "$fixture/tests/DevelopmentProbe.php" > "$work_root/command.log" 2>&1 || fail 'annotation no longer suppresses the locked checker'
	if run check:coverage; then fail 'blanket annotation escaped independent token guard'; fi
	grep -q 'Blanket PHPCS suppression' "$work_root/command.log" || fail 'annotation failed for an unrelated reason'
done
printf '<?php\n// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Synthetic external signature.\nclass NamingProbe { public function hiddenBadName() {} }\nclass OtherNamingProbe { public function visibleBadName() {} }\n' > "$fixture/tests/DevelopmentProbe.php"
run check:coverage || fail 'explained exact-code annotation was rejected'
"$repo_root/vendor/bin/phpcs" --standard=RANOwnedMethods -s "$fixture/tests/DevelopmentProbe.php" > "$work_root/command.log" 2>&1 && fail 'precise annotation suppressed adjacent declaration'
grep -q 'visibleBadName' "$work_root/command.log" || fail 'adjacent naming diagnostic disappeared'
if grep -q 'hiddenBadName' "$work_root/command.log"; then fail 'exact-code positive annotation did not apply'; fi
rm "$fixture/tests/DevelopmentProbe.php"
run standards || fail 'restored development controls do not pass'
run check:coverage || fail 'restored coverage controls do not pass'
printf 'Development standards controls passed: new tests/scripts, inherited methods, narrowed selection, blanket directives and two stable fixer passes.\n'

# The existing PHPStan declaration is maintained PHP, with one foreign function.
cp "$fixture/tests/phpstan/wordpress-http.stub" "$work_root/http-contract"
printf '\nfunction unowned_stub_probe() {}\n' >> "$fixture/tests/phpstan/wordpress-http.stub"
if run standards; then fail 'unprefixed declaration beside the foreign stub function escaped canonical standards'; fi
"$repo_root/vendor/bin/phpcs" --standard="$fixture/.phpcs.xml" -s "$fixture/tests/phpstan/wordpress-http.stub" > "$work_root/stub-report" 2>&1 && fail 'stub prefix negative unexpectedly passed'
grep -q 'NonPrefixedFunctionFound' "$work_root/stub-report" || fail 'stub diagnostic missing'
grep -q 'unowned_stub_probe' "$work_root/stub-report" || fail 'adjacent declaration diagnostic missing'
if grep -q 'Found: "wp_remote_get"' "$work_root/stub-report"; then fail 'foreign function exception failed'; fi
cp "$work_root/http-contract" "$fixture/tests/phpstan/wordpress-http.stub"
printf '\n// phpcs:disable WordPress\n' >> "$fixture/tests/phpstan/wordpress-http.stub"
if run check:coverage; then fail 'stub annotation escaped independent token guard'; fi
grep -q 'Blanket PHPCS suppression' "$work_root/command.log" || fail 'stub annotation control failed for unrelated reason'
cp "$work_root/http-contract" "$fixture/tests/phpstan/wordpress-http.stub"
run standards || fail 'restored foreign declaration did not pass canonical standards'
run check:coverage || fail 'restored stub scope did not pass coverage'
printf 'HTTP contract standards controls passed: exact foreign name, adjacent declaration and annotation inventory.\n'

# A future standalone file cannot inherit the seven existing variable exemptions.
printf '%s\n' '<?php' '// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.' '$unowned_probe = 1;' > "$fixture/tests/future-variable-scope.php"
if run check:coverage; then fail 'future file inherited persistent variable exemption'; fi
grep -q 'Blanket PHPCS suppression or unreviewed directive in tests/future-variable-scope.php' "$work_root/command.log" || fail 'future variable exemption control did not execute'
rm "$fixture/tests/future-variable-scope.php"
cp "$fixture/tests/bootstrap.php" "$work_root/bootstrap.clean"
printf '\nfunction unrelated_function_probe() {}\n' >> "$fixture/tests/bootstrap.php"
if run standards; then fail 'variable exemption hid unrelated function'; fi
grep -q 'NonPrefixedFunctionFound' "$work_root/command.log" || fail 'unrelated declaration control did not execute'
cp "$work_root/bootstrap.clean" "$fixture/tests/bootstrap.php"
run check:coverage || fail 'restored variable exemptions failed'
run standards || fail 'restored variable fixture failed'
printf 'Persistent variable controls passed: seven existing files, future file rejected, unrelated function checked.\n'
