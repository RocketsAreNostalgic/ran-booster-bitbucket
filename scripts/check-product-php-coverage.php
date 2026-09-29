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
	if ($entry->isFile() && $entry->getExtension() === 'php') {
		$files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1));
	}
}
sort($files, SORT_STRING);
if ($files === []) {
	throw new RuntimeException('No maintained product PHP files were discovered.');
}

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$commands = [
	'analyze' => ['vendor/bin/phpstan analyse', '--configuration=phpstan.neon.dist', '--configuration'],
	'standards' => ['vendor/bin/phpcs', '--standard=.phpcs.xml', '--standard'],
	'standards:fix' => ['vendor/bin/phpcbf', '--standard=.phpcs.xml', '--standard'],
];
foreach ($commands as $name => [$executable, $config, $option]) {
	$command = $composer['scripts'][$name] ?? null;
	if (!is_string($command) || !str_starts_with($command, $executable . ' ')
		|| substr_count($command, $option) !== 1 || !str_contains($command, $config)) {
		throw new RuntimeException("Review the actual Composer $name configuration before certifying coverage.");
	}
}

// Keep the supported local scope grammar narrow. New includes/exclusions or a
// changed format require an explicit review instead of silently widening this proof.
$neon = file_get_contents($root . '/phpstan.neon.dist');
if ($neon === false || preg_match('/^\s*excludePaths\s*:/m', $neon)) {
	throw new RuntimeException('Cannot certify PHPStan scope with unreadable or excluded paths.');
}
if (preg_match_all('/^includes\s*:/m', $neon) !== 1
	|| !preg_match('/^includes:\s*\R\t- vendor\/szepeviktor\/phpstan-wordpress\/extension\.neon\R\Rparameters:/m', $neon)) {
	throw new RuntimeException('Review PHPStan includes before certifying coverage.');
}
if (preg_match_all('/^\s*paths\s*:/m', $neon) !== 1
	|| !preg_match('/^\tpaths:\s*\R((?:\t\t- [^\r\n]+\R?)+)/m', $neon, $match)) {
	throw new RuntimeException('Review PHPStan paths before certifying coverage.');
}
$analysis = [];
foreach (preg_split('/\R/', rtrim($match[1], "\r\n")) as $line) {
	$analysis[] = substr($line, strlen("\t\t- "));
}

$xml = new DOMDocument();
if (!@$xml->load($root . '/.phpcs.xml', LIBXML_NONET)) {
	throw new RuntimeException('Cannot parse the PHPCS ruleset.');
}
$xpath = new DOMXPath($xml);
if ($xpath->query('//exclude-pattern')->length !== 0) {
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
		if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')
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
