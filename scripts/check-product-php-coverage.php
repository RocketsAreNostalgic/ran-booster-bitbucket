<?php

declare(strict_types=1);

// This repository-level guard deliberately does not depend on the certified Core.
$root                = dirname( __DIR__ );
$ignored_directories = array( '.git', '.github', '.phpstan', '.phpunit.cache', 'build', 'node_modules', 'scripts', 'tests', 'vendor' );
$directory           = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS );
$filter              = new RecursiveCallbackFilterIterator(
	$directory,
	static function ( SplFileInfo $entry ) use ( $ignored_directories, $root ): bool {
		$relative = substr( $entry->getPathname(), strlen( $root ) + 1 );
		return ! $entry->isDir() || ! in_array( $relative, $ignored_directories, true );
	},
);
$files               = array();
foreach ( new RecursiveIteratorIterator( $filter ) as $entry ) {
	if ( $entry->isFile() && strcasecmp( $entry->getExtension(), 'php' ) === 0 ) {
		if ( $entry->getExtension() !== 'php' ) {
			throw new RuntimeException( 'Review unsupported product PHP extension: ' . $entry->getPathname() );
		}
		$files[] = str_replace( DIRECTORY_SEPARATOR, '/', substr( $entry->getPathname(), strlen( $root ) + 1 ) );
	}
}
sort( $files, SORT_STRING );
if ( array() === $files ) {
	throw new RuntimeException( 'No maintained product PHP files were discovered.' );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local repository or archive bytes in standalone CLI tooling; no remote HTTP request.
$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR );
$commands = array(
	'analyze'       => 'vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --debug --memory-limit=512M',
	'standards'     => 'vendor/bin/phpcs --standard=.phpcs.xml --report=summary',
	'standards:fix' => 'vendor/bin/phpcbf --standard=.phpcs.xml --report=summary',
);
foreach ( $commands as $name => $expected ) {
	if ( ( $composer['scripts'][ $name ] ?? null ) !== $expected ) {
		throw new RuntimeException( "Review the actual Composer $name configuration before certifying coverage." );
	}
}

// Parse NEON with the locked PHPStan adapter so merge keys, quoted keys and
// comments have the same meaning here as they do in the analysis command.
require $root . '/vendor/autoload.php';
$config = ( new PHPStan\DependencyInjection\NeonAdapter( array() ) )->load( $root . '/phpstan.neon.dist' );
if ( ( $config['includes'] ?? null ) !== array( 'vendor/szepeviktor/phpstan-wordpress/extension.neon' ) ) {
	throw new RuntimeException( 'Review PHPStan includes before certifying coverage.' );
}
$parameters = $config['parameters'] ?? array();
if ( array_key_exists( 'excludePaths', $parameters ) ) {
	throw new RuntimeException( 'Cannot certify PHPStan scope with excluded paths.' );
}
if ( ! isset( $parameters['paths'] ) || ! is_array( $parameters['paths'] ) ) {
	throw new RuntimeException( 'Review PHPStan paths before certifying coverage.' );
}
if ( array_key_exists( 'fileExtensions', $parameters )
	&& ( ! is_array( $parameters['fileExtensions'] ) || ! in_array( 'php', $parameters['fileExtensions'], true ) ) ) {
	throw new RuntimeException( 'PHPStan file extensions omit PHP.' );
}
$analysis = $parameters['paths'];

$xml = new DOMDocument();
// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Convert malformed local XML into the explicit coverage-contract exception below.
if ( ! @$xml->load( $root . '/.phpcs.xml', LIBXML_NONET ) ) {
	throw new RuntimeException( 'Cannot parse the PHPCS ruleset.' );
}
$xpath = new DOMXPath( $xml );
// Only the reviewed diagnostic-specific development prefix exceptions are allowed.
// Production exclusions, whole-file exclusions and unrelated rule exclusions fail.
$prefix_exceptions = array(
	'NonPrefixedNamespaceFound' => array( '/tests/' ),
	'NonPrefixedVariableFound'  => array( '/tests/bootstrap.php', '/tests/phpstan-bootstrap.php', '/tests/fixtures/plugin-lifecycle.php', '/tests/WordPress/', '/scripts/' ),
	'NonPrefixedConstantFound'  => array( '/tests/bootstrap.php', '/tests/phpstan-bootstrap.php', '/tests/fixtures/plugin-lifecycle.php', '/tests/WordPress/bitbucket-installed-inert.php', '/scripts/verify-release.php' ),
	'NonPrefixedFunctionFound'  => array( '/tests/bootstrap.php', '/tests/fixtures/plugin-lifecycle.php', '/scripts/verify-release.php' ),
	'NonPrefixedHooknameFound'  => array( '/tests/WordPress/' ),
);
if ( 0 !== $xpath->query( '//arg[@name="ignore"]' )->length ) {
	throw new RuntimeException( 'Review PHPCS exclusions before certifying coverage.' );
}
foreach ( $xpath->query( '//exclude-pattern' ) as $exclusion ) {
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM property spelling.
	$parent = $exclusion->parentNode;
	$rule   = $parent instanceof DOMElement ? $parent->getAttribute( 'ref' ) : '';
	$code   = str_starts_with( $rule, 'WordPress.NamingConventions.PrefixAllGlobals.' ) ? substr( $rule, strlen( 'WordPress.NamingConventions.PrefixAllGlobals.' ) ) : '';
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM property spelling.
	if ( ! in_array( trim( $exclusion->textContent ), $prefix_exceptions[ $code ] ?? array(), true ) ) {
		throw new RuntimeException( 'Review PHPCS exclusions before certifying coverage.' );
	}
}
$standards = array();
foreach ( $xpath->query( '/ruleset/file' ) as $node ) {
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve the native DOM/ZipArchive property spelling; this receiver is not owned.
	$standards[] = trim( $node->textContent );
}
$extensions = $xpath->query( '/ruleset/arg[@name="extensions"]' );
if ( 1 !== $extensions->length || 'php' !== $extensions->item( 0 )->getAttribute( 'value' ) ) {
	throw new RuntimeException( 'Review PHPCS extensions before certifying PHP coverage.' );
}

foreach ( array(
	'PHPStan'      => $analysis,
	'PHPCS/PHPCBF' => $standards,
) as $tool => $paths ) {
	if ( array() === $paths ) {
		throw new RuntimeException( "$tool selects no product paths." );
	}
	foreach ( $paths as $fixture_path ) {
		if ( ! is_string( $fixture_path ) || '' === $fixture_path || str_starts_with( $fixture_path, '/' ) || str_contains( $fixture_path, '..' )
			|| str_contains( $fixture_path, '*' ) || ( ! is_file( $root . '/' . $fixture_path ) && ! is_dir( $root . '/' . $fixture_path ) ) ) {
			throw new RuntimeException( "Review unsupported or missing $tool path: $fixture_path" );
		}
	}
	$selected_files = $files;
	if ( 'PHPCS/PHPCBF' === $tool ) {
		foreach ( array( 'tests', 'scripts' ) as $development_directory ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $development_directory, FilesystemIterator::SKIP_DOTS ) ) as $development_file ) {
				if ( $development_file->isFile() && 'php' === strtolower( $development_file->getExtension() ) ) {
					if ( 'php' !== $development_file->getExtension() ) {
						throw new RuntimeException( 'Review unsupported development PHP extension.' );
					}
					$selected_files[] = substr( $development_file->getPathname(), strlen( $root ) + 1 );
				}
			}
		}
	}
	foreach ( $selected_files as $file ) {
		$covered = false;
		foreach ( $paths as $fixture_path ) {
			if ( $file === $fixture_path || ( is_dir( $root . '/' . $fixture_path ) && str_starts_with( $file, rtrim( $fixture_path, '/' ) . '/' ) ) ) {
				$covered = true;
				break;
			}
		}
		if ( ! $covered ) {
			throw new RuntimeException( "$tool does not directly select maintained PHP: $file" );
		}
	}
}


foreach ( array_unique( $selected_files ) as $selected_file ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect owned PHP comments without executing the source.
	foreach ( token_get_all( file_get_contents( $root . '/' . $selected_file ) ) as $token ) {
		if ( ! is_array( $token ) || ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$source_comment = trim( preg_replace( '/[\s*\/]+/', ' ', $token[1] ) );
		if ( 1 === preg_match( '/(?:@?phpcs:ignorefile\b|@codingStandardsIgnore(?:File|Start|Line)\b|@?phpcs:(?:disable|ignore)(?=\s*(?:--|$)))/i', $source_comment ) ) {
			throw new RuntimeException( 'Blanket PHPCS suppression in ' . $selected_file );
		}
	}
}

printf( "Maintained PHP coverage: %d product files analysed; %d product/development files checked by PHPCS/PHPCBF.\n", count( $files ), count( array_unique( $selected_files ) ) );
