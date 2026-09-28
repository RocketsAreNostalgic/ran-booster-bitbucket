#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work_root=$(mktemp -d "${TMPDIR:-/tmp}/ran-bitbucket-syntax-test.XXXXXX")
trap 'rm -rf "$work_root"' EXIT
fixture="$work_root/repository with spaces"
mkdir -p "$fixture/scripts" "$fixture/vendor" "$fixture/.phpstan" "$fixture/.phpunit.cache" "$work_root/bin"
cp "$repo_root/scripts/lint-syntax.sh" "$fixture/scripts/lint-syntax.sh"
# Exercise the actual canonical Composer command, not a copied shell expression.
php -r '$source = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo json_encode(["scripts" => ["lint:syntax" => $source["scripts"]["lint:syntax"]]], JSON_THROW_ON_ERROR);' \
	"$repo_root/composer.json" > "$fixture/composer.json"
printf '<?php\n' > "$fixture/valid file.php"
printf '<?php\n' > "$fixture/line
break.php"
for excluded in vendor .phpstan .phpunit.cache; do
	printf '<?php syntax error\n' > "$fixture/$excluded/broken.php"
done

fail() {
	printf 'syntax regression: %s\n' "$*" >&2
	exit 1
}

composer --working-dir="$fixture" lint:syntax > "$work_root/clean.log" 2>&1 || fail 'valid filenames or excluded paths failed'

printf '<?php syntax error\n' > "$fixture/invalid file.php"
if composer --working-dir="$fixture" lint:syntax > "$work_root/invalid.log" 2>&1; then
	fail 'invalid PHP was accepted'
fi
rm "$fixture/invalid file.php"

# Discovery can fail before emitting names or after emitting a valid name.
for mode in empty partial; do
	cat > "$work_root/bin/find" <<'FIND'
#!/usr/bin/env bash
if [[ "$DISCOVERY_MODE" == partial ]]; then
	printf './valid file.php\0'
fi
printf 'intentional discovery failure\n' >&2
exit 71
FIND
	chmod +x "$work_root/bin/find"
	if DISCOVERY_MODE="$mode" PATH="$work_root/bin:$PATH" composer --working-dir="$fixture" lint:syntax > "$work_root/$mode.log" 2>&1; then
		fail "$mode discovery failure was accepted"
	fi
	grep -q 'intentional discovery failure' "$work_root/$mode.log" || fail 'discovery control did not execute'
done

# Successful but empty discovery must not become an empty passing parser sweep.
printf '#!/usr/bin/env bash\nexit 0\n' > "$work_root/bin/find"
if PATH="$work_root/bin:$PATH" composer --working-dir="$fixture" lint:syntax > "$work_root/no-files.log" 2>&1; then
	fail 'empty source discovery was accepted'
fi
printf 'PHP syntax regression passed: valid unusual filenames, exclusions, invalid PHP, failed discovery (empty/partial), empty discovery.\n'
