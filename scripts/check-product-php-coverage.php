<?php

declare(strict_types=1);

// This repository-level guard deliberately does not depend on the certified Core.
$root = dirname(__DIR__);
$ignoredDirectories = ['.git', '.github', '.phpstan', '.phpunit.cache', 'build', 'node_modules', 'scripts', 'tests', 'vendor'];
$directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$filter = new RecursiveCallbackFilterIterator(
	$directory,
	static function (SplFileInfo $entry) use ($ignoredDirectories, $root): bool {
		$relative = substr($entry->getPathname(), strlen($root) + 1);
		return !$entry->isDir() || !in_array($relative, $ignoredDirectories, true);
	},
);
$files = [];
foreach (new RecursiveIteratorIterator($filter) as $entry) {
	if ($entry->isFile() && strcasecmp($entry->getExtension(), 'php') === 0) {
		if ($entry->getExtension() !== 'php') {
			throw new RuntimeException('Review unsupported product PHP extension: ' . $entry->getPathname());
		}
		$files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1));
	}
}
sort($files, SORT_STRING);
if ($files === []) {
	throw new RuntimeException('No maintained product PHP files were discovered.');
}

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$commands = [
	'analyze' => 'vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --debug --memory-limit=512M',
	'standards' => 'vendor/bin/phpcs --standard=.phpcs.xml --report=summary',
	'standards:fix' => 'vendor/bin/phpcbf --standard=.phpcs.xml --report=summary',
];
foreach ($commands as $name => $expected) {
	if (($composer['scripts'][$name] ?? null) !== $expected) {
		throw new RuntimeException("Review the actual Composer $name configuration before certifying coverage.");
	}
}

// Parse NEON with the locked PHPStan adapter so merge keys, quoted keys and
// comments have the same meaning here as they do in the analysis command.
require $root . '/vendor/autoload.php';
$config = (new PHPStan\DependencyInjection\NeonAdapter([]))->load($root . '/phpstan.neon.dist');
if (($config['includes'] ?? null) !== ['vendor/szepeviktor/phpstan-wordpress/extension.neon']) {
	throw new RuntimeException('Review PHPStan includes before certifying coverage.');
}
$parameters = $config['parameters'] ?? [];
if (array_key_exists('excludePaths', $parameters)) {
	throw new RuntimeException('Cannot certify PHPStan scope with excluded paths.');
}
if (!isset($parameters['paths']) || !is_array($parameters['paths'])) {
	throw new RuntimeException('Review PHPStan paths before certifying coverage.');
}
if (array_key_exists('fileExtensions', $parameters)
	&& (!is_array($parameters['fileExtensions']) || !in_array('php', $parameters['fileExtensions'], true))) {
	throw new RuntimeException('PHPStan file extensions omit PHP.');
}
$analysis = $parameters['paths'];

$xml = new DOMDocument();
if (!@$xml->load($root . '/.phpcs.xml', LIBXML_NONET)) {
	throw new RuntimeException('Cannot parse the PHPCS ruleset.');
}
$xpath = new DOMXPath($xml);
if ($xpath->query('//exclude-pattern | //arg[@name="ignore"]')->length !== 0) {
	throw new RuntimeException('Review PHPCS exclusions before certifying coverage.');
}
$standards = [];
foreach ($xpath->query('/ruleset/file') as $node) {
	$standards[] = trim($node->textContent);
}
$extensions = $xpath->query('/ruleset/arg[@name="extensions"]');
if ($extensions->length !== 1 || $extensions->item(0)->getAttribute('value') !== 'php') {
	throw new RuntimeException('Review PHPCS extensions before certifying PHP coverage.');
}

foreach (['PHPStan' => $analysis, 'PHPCS/PHPCBF' => $standards] as $tool => $paths) {
	if ($paths === []) {
		throw new RuntimeException("$tool selects no product paths.");
	}
	foreach ($paths as $path) {
		if (!is_string($path) || $path === '' || str_starts_with($path, '/') || str_contains($path, '..')
			|| str_contains($path, '*') || (!is_file($root . '/' . $path) && !is_dir($root . '/' . $path))) {
			throw new RuntimeException("Review unsupported or missing $tool path: $path");
		}
	}
	foreach ($files as $file) {
		$covered = false;
		foreach ($paths as $path) {
			if ($file === $path || (is_dir($root . '/' . $path) && str_starts_with($file, rtrim($path, '/') . '/'))) {
				$covered = true;
				break;
			}
		}
		if (!$covered) {
			throw new RuntimeException("$tool does not directly select maintained PHP: $file");
		}
	}
}

printf("Maintained PHP coverage: %d files directly selected by PHPStan and PHPCS/PHPCBF.\n", count($files));
