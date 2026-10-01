#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work_root=$(mktemp -d "${TMPDIR:-/tmp}/ran-bitbucket-api13-naming.XXXXXX")
trap 'rm -rf "$work_root"' EXIT
cd "$repo_root"

# Use the actual repository scope and activated rule. Ignore annotations here so
# an obsolete API-12 exception cannot conceal a reverted declaration.
vendor/bin/phpcs --standard=.phpcs.xml --sniffs=RANOwnedMethods.NamingConventions.ValidMethodName --ignore-annotations --no-colors -q --report=json > "$work_root/positive.json"

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
