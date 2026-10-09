<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.

declare(strict_types=1);

// This repository-level guard deliberately does not depend on the certified Core.
$root                = dirname( __DIR__ );
$ignored_directories = array( '.git', '.phpstan', '.phpunit.cache', 'build', 'node_modules', 'scripts', 'tests', 'vendor' );
$directory           = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS );
$filter              = new RecursiveCallbackFilterIterator(
	$directory,
	static function ( SplFileInfo $entry ) use ( $ignored_directories, $root ): bool {
		$relative = substr( $entry->getPathname(), strlen( $root ) + 1 );
		return ! $entry->isDir() || ! in_array( $relative, $ignored_directories, true );
	},
);
// Unknown suffixes may be PHP includes too. Only explicit documentation/data
// formats and declared Bash scripts retain bounded inspection of their examples.
$unsupported_php = static function ( SplFileInfo $file ): bool {
	$extension = strtolower( $file->getExtension() );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Identify local document/data and Bash-script headers without executing source.
	$header = file_get_contents( $file->getPathname(), false, null, 0, 256 );
	if ( false === $header ) {
		throw new RuntimeException( 'Cannot inspect maintained file for PHP coverage.' );
	}
	$inert = in_array( $extension, array( 'md', 'json' ), true )
		|| ( 'sh' === $extension && ( str_starts_with( $header, "#!/usr/bin/env bash\n" ) || str_starts_with( $header, "#!/bin/bash\n" ) ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect every potentially executable source body, including unknown suffixes, without executing it.
	$contents = $inert ? $header : file_get_contents( $file->getPathname() );
	if ( false === $contents ) {
		throw new RuntimeException( 'Cannot inspect maintained file for PHP coverage.' );
	}
	// Only a genuine leading XML declaration is data rather than a possible short PHP tag.
	$contents = preg_replace(
		'~\A(?:\xEF\xBB\xBF)?<\?xml[ \t\r\n]+version[ \t\r\n]*=[ \t\r\n]*(?:"1\.[01]"|\'1\.[01]\')(?:[ \t\r\n]+encoding[ \t\r\n]*=[ \t\r\n]*(?:"[A-Za-z][A-Za-z0-9._-]*"|\'[A-Za-z][A-Za-z0-9._-]*\'))?(?:[ \t\r\n]+standalone[ \t\r\n]*=[ \t\r\n]*(?:"(?:yes|no)"|\'(?:yes|no)\'))?[ \t\r\n]*\?>~',
		'',
		$contents
	);
	return 'phtml' === $extension || null === $contents || 1 === preg_match( $inert ? '/^(?:\xEF\xBB\xBF)?(?:#![^\n]*\n)?\s*<\?/' : '/<\?/', $contents );
};
$files           = array();
foreach ( new RecursiveIteratorIterator( $filter ) as $entry ) {
	if ( $entry->isFile() && strcasecmp( $entry->getExtension(), 'php' ) === 0 ) {
		if ( $entry->getExtension() !== 'php' ) {
			throw new RuntimeException( 'Review unsupported product PHP extension: ' . $entry->getPathname() );
		}
		$files[] = str_replace( DIRECTORY_SEPARATOR, '/', substr( $entry->getPathname(), strlen( $root ) + 1 ) );
	} elseif ( $entry->isFile() ) {
		if ( $unsupported_php( $entry ) ) {
			throw new RuntimeException( 'Review production PHP outside lowercase .php before certifying analysis coverage.' );
		}
	}
}
sort( $files, SORT_STRING );
if ( array() === $files ) {
	throw new RuntimeException( 'No maintained product PHP files were discovered.' );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local repository or archive bytes in standalone CLI tooling; no remote HTTP request.
$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR );
$commands = array(
	'analyze:development' => 'vendor/bin/phpstan analyse --configuration=phpstan-development.neon.dist --no-progress --debug --memory-limit=512M',
	'analyze'             => 'vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --debug --memory-limit=512M',
	'standards'           => 'vendor/bin/phpcs --standard=.phpcs.xml --report=summary',
	'standards:fix'       => 'vendor/bin/phpcbf --standard=.phpcs.xml --report=summary',
);
foreach ( $commands as $name => $expected ) {
	if ( ( $composer['scripts'][ $name ] ?? null ) !== $expected ) {
		throw new RuntimeException( "Review the actual Composer $name configuration before certifying coverage." );
	}
}

// Parse NEON with the locked PHPStan adapter so merge keys, quoted keys and
// comments have the same meaning here as they do in the analysis command.
require $root . '/vendor/autoload.php';
// @phpstan-ignore phpstanApi.method, phpstanApi.constructor (Locked PHPStan discovery adapter; upgrade requires the existing actual-command contract review.)
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
$xpath      = new DOMXPath( $xml );
$exclusions = $xpath->query( '//include-pattern | //exclude-pattern | //exclude | //severity | //arg[@name="ignore"] | //@phpcs-only | //@phpcbf-only' );
if ( false === $exclusions || 0 !== $exclusions->length ) {
	throw new RuntimeException( 'Review PHPCS exclusions before certifying coverage.' );
}
// Preserve the reviewed local rule ancestry and naming/compatibility properties.
$rule_refs = array();
$rules     = $xpath->query( '/ruleset/rule' );
if ( false === $rules ) {
	throw new RuntimeException( 'Cannot inspect the PHPCS ruleset.' );
}
foreach ( $rules as $rule ) {
	if ( ! $rule instanceof DOMElement ) {
		throw new RuntimeException( 'Expected a PHPCS ruleset element.' );
	}
	$rule_refs[] = $rule->getAttribute( 'ref' );
}
if ( array( 'RANWordPressPlugin', 'RANOwnedMethods', 'WordPress.NamingConventions.PrefixAllGlobals', 'WordPress.WP.I18n' ) !== $rule_refs ) {
	throw new RuntimeException( 'Review PHPCS rule ancestry before certifying coverage.' );
}
$properties     = array();
$property_nodes = $xpath->query( '//property' );
if ( false === $property_nodes ) {
	throw new RuntimeException( 'Cannot inspect the PHPCS ruleset.' );
}
foreach ( $property_nodes as $property ) {
	if ( ! $property instanceof DOMElement ) {
		throw new RuntimeException( 'Expected a PHPCS ruleset element.' );
	}
	$values   = array();
	$elements = $xpath->query( './element', $property );
	if ( false === $elements ) {
		throw new RuntimeException( 'Cannot inspect the PHPCS ruleset.' );
	}
	foreach ( $elements as $element ) {
		if ( ! $element instanceof DOMElement ) {
			throw new RuntimeException( 'Expected a PHPCS ruleset element.' );
		}
		$values[] = $element->getAttribute( 'value' );
	}
	$properties[] = array( $xpath->evaluate( 'string(../../@ref)', $property ), $property->getAttribute( 'name' ), $property->getAttribute( 'type' ), $property->getAttribute( 'value' ), $values );
}
if ( array(
	array( 'WordPress.NamingConventions.PrefixAllGlobals', 'prefixes', 'array', '', array( 'ran_booster_bitbucket', 'RAN\\\\' ) ),
	array( 'WordPress.WP.I18n', 'text_domain', 'array', '', array( 'ran-booster-bitbucket' ) ),
) !== $properties ) {
	throw new RuntimeException( 'Review PHPCS properties before certifying coverage.' );
}
$configs      = array();
$config_nodes = $xpath->query( '//config' );
if ( false === $config_nodes ) {
	throw new RuntimeException( 'Cannot inspect the PHPCS ruleset.' );
}
foreach ( $config_nodes as $config_node ) {
	if ( ! $config_node instanceof DOMElement ) {
		throw new RuntimeException( 'Expected a PHPCS ruleset element.' );
	}
	$configs[] = array( $config_node->getAttribute( 'name' ), $config_node->getAttribute( 'value' ) );
}
if ( array( array( 'minimum_wp_version', '7.0' ), array( 'testVersion', '8.2-' ) ) !== $configs ) {
	throw new RuntimeException( 'Review PHPCS support or checker configuration before certifying coverage.' );
}
$arguments      = array();
$argument_nodes = $xpath->query( '//arg' );
if ( false === $argument_nodes ) {
	throw new RuntimeException( 'Cannot inspect the PHPCS ruleset.' );
}
foreach ( $argument_nodes as $node ) {
	if ( ! $node instanceof DOMElement ) {
		throw new RuntimeException( 'Expected a PHPCS ruleset element.' );
	}
	$arguments[] = array( $node->getAttribute( 'name' ), $node->getAttribute( 'value' ) );
}
if ( array( array( 'basepath', '.' ), array( 'colors', '' ), array( 'extensions', 'php,stub' ), array( 'parallel', '4' ), array( '', 'sp' ) ) !== $arguments ) {
	throw new RuntimeException( 'Review PHPCS exclusions or command arguments before certifying coverage.' );
}
$standards  = array();
$file_nodes = $xpath->query( '/ruleset/file' );
if ( false === $file_nodes ) {
	throw new RuntimeException( 'Cannot inspect the PHPCS ruleset.' );
}
foreach ( $file_nodes as $node ) {
	if ( ! $node instanceof DOMElement ) {
		throw new RuntimeException( 'Expected a PHPCS file element.' );
	}
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve the native DOM/ZipArchive property spelling; this receiver is not owned.
	$standards[] = trim( $node->textContent );
}
$extensions = $xpath->query( '/ruleset/arg[@name="extensions"]' );
if ( false === $extensions ) {
	throw new RuntimeException( 'Cannot inspect PHPCS extensions.' );
}
$extension = $extensions->item( 0 );
if ( 1 !== $extensions->length || ! $extension instanceof DOMElement || 'php,stub' !== $extension->getAttribute( 'value' ) ) {
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
				if ( $development_file->isFile() && ( 'php' === strtolower( $development_file->getExtension() ) || $root . '/tests/phpstan/wordpress-http.stub' === $development_file->getPathname() ) ) {
					if ( 'php' !== $development_file->getExtension() && $root . '/tests/phpstan/wordpress-http.stub' !== $development_file->getPathname() ) {
						throw new RuntimeException( 'Review unsupported development PHP extension.' );
					}
					$selected_files[] = substr( $development_file->getPathname(), strlen( $root ) + 1 );
				} elseif ( $development_file->isFile() && $root . '/tests/phpstan/wordpress-http.stub' !== $development_file->getPathname() ) {
					if ( $unsupported_php( $development_file ) ) {
						throw new RuntimeException( 'Review development PHP outside lowercase .php before certifying coverage.' );
					}
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


// Container construction resolves imports but does not execute bootstrap files.
// Preserve these exact locked-tool and project identities, including multiplicity.
$assert_bootstraps = static function ( array $actual ) use ( $root ): void {
	$runtime  = 'phar://' . realpath( $root . '/vendor/phpstan/phpstan/phpstan.phar' ) . '/stubs/runtime/';
	$expected = array(
		$runtime . 'ReflectionUnionType.php',
		$runtime . 'ReflectionAttribute.php',
		$runtime . 'Attribute85.php',
		$runtime . 'ReflectionIntersectionType.php',
		$root . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php',
		$root . '/vendor/szepeviktor/phpstan-wordpress/bootstrap.php',
		$root . '/tests/phpstan-bootstrap.php',
	);
	if ( $expected !== $actual ) {
		throw new RuntimeException( 'Review effective PHPStan bootstrap identities before certifying coverage.' );
	}
};

// Verify actual analysis selection, rather than treating lexical path ancestry as proof.
$temp = sys_get_temp_dir() . '/ran-bitbucket-coverage-' . bin2hex( random_bytes( 12 ) );
try {
	$container = ( new PHPStan\DependencyInjection\ContainerFactory( $root ) )->create( $temp, array( $root . '/phpstan.neon.dist' ), array() );
	$assert_bootstraps( $container->getParameter( 'bootstrapFiles' ) );
	$actual = $container->getService( 'fileFinderAnalyse' )->findFiles( $container->getParameter( 'paths' ) )->getFiles();
	// CommandHelper removes configured stubs after FileFinder discovery.
	// @phpstan-ignore phpstanApi.constructor, phpstanApi.constructor (Match locked CLI stub filtering; regression controls prove production-stub omissions fail.)
	$stub_excluder = new PHPStan\File\FileExcluder( new PHPStan\File\FileHelper( $root ), $container->getParameter( 'stubFiles' ) );
	// @phpstan-ignore phpstanApi.method (Use the locked CLI exclusion semantics; upgrade requires contract requalification.)
	$actual   = array_values( array_filter( $actual, static fn( string $file ): bool => ! $stub_excluder->isExcludedFromAnalysing( $file ) ) );
	$expected = array_map( static fn( string $file ): string => $root . '/' . $file, $files );
	if ( array() !== array_diff( $expected, $actual ) || array() !== array_diff( $actual, $expected ) ) {
		throw new RuntimeException( 'Effective PHPStan selection differs from discovered production PHP.' );
	}
} finally {
	if ( is_dir( $temp ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $temp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $entry ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the private container-cache directory created above.
			$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the unique private container cache after its contents.
		rmdir( $temp );
	}
}

/** Reject broad or unexplained directives without interpreting inert fixture strings. */
function ran_booster_bitbucket_has_broad_directive( string $source, string $path ): bool {
	foreach ( token_get_all( $source ) as $token ) {
		if ( ! is_array( $token ) || ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		if ( preg_match( '/@codingStandards(?:Ignore|ChangeSetting)|@phpcs:/i', $token[1] ) ) {
			return true;
		}
		preg_match_all( '/phpcs:(ignorefile\S*|disable\S*|ignore\S*|set\S*)([^\r\n]*)/i', $token[1], $directives, PREG_SET_ORDER );
		foreach ( $directives as $directive ) {
			$operation = strtolower( $directive[1] );
			if ( ! in_array( $operation, array( 'ignore', 'disable' ), true ) ) {
				return true;
			}
			$parts = explode( ' -- ', trim( $directive[2], ' 	*/' ), 2 );
			if ( 2 !== count( $parts ) || '' === trim( $parts[1] ) ) {
				return true;
			}
			foreach ( explode( ',', $parts[0] ) as $code ) {
				if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*){3}$/D', trim( $code ) ) ) {
					return true;
				}
			}
			// Only standalone process variables retain a persistent exemption.
			if ( 'disable' === $operation && ( 2 !== $token[2]
				|| ! in_array(
					$path,
					array(
						'scripts/check-product-php-coverage.php',
						'scripts/verify-release.php',
						'tests/bootstrap.php',
						'tests/phpstan-bootstrap.php',
						'tests/fixtures/plugin-lifecycle.php',
						'tests/WordPress/bitbucket-installed-inert.php',
						'tests/WordPress/bitbucket-installed-smoke.php',
					),
					true
				)
				|| 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound' !== trim( $parts[0] ) ) ) {
				return true;
			}
		}
	}
	return false;
}

foreach ( array_unique( $selected_files ) as $selected_file ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect owned PHP comments without executing the source.
	$source = file_get_contents( $root . '/' . $selected_file );
	if ( false === $source ) {
		throw new RuntimeException( 'Cannot inspect maintained source directives.' );
	}
	if ( ran_booster_bitbucket_has_broad_directive( $source, $selected_file ) ) {
		throw new RuntimeException( 'Blanket PHPCS suppression or unreviewed directive in ' . $selected_file );
	}
}

// The development profile is independently checked against maintained PHP not
// covered by the separate product gate. Production fixture inference stays isolated.
// @phpstan-ignore phpstanApi.method, phpstanApi.constructor (Read the locked NEON profile with the same adapter as product discovery.)
$development = ( new PHPStan\DependencyInjection\NeonAdapter( array() ) )->load( $root . '/phpstan-development.neon.dist' );
if ( array_key_exists( 'ignoreErrors', $parameters ) || array_key_exists( 'ignoreErrors', $development['parameters'] ?? array() ) ) {
	throw new RuntimeException( 'Broad analysis ignore lists require an explicit scope decision.' );
}
if ( ( $parameters['level'] ?? 0 ) < 8 || ( $development['parameters']['level'] ?? 0 ) < 8 ) {
	throw new RuntimeException( 'Product level 8 and development level 8 are required.' );
}
if ( ( $development['includes'] ?? null ) !== array( 'vendor/szepeviktor/phpstan-wordpress/extension.neon' ) ) {
	throw new RuntimeException( 'Review development PHPStan includes before certifying coverage.' );
}
$temp = sys_get_temp_dir() . '/ran-bitbucket-development-' . bin2hex( random_bytes( 12 ) );
try {
	$container = ( new PHPStan\DependencyInjection\ContainerFactory( $root ) )->create( $temp, array( $root . '/phpstan-development.neon.dist' ), array() );
	$assert_bootstraps( $container->getParameter( 'bootstrapFiles' ) );
	$actual = $container->getService( 'fileFinderAnalyse' )->findFiles( $container->getParameter( 'paths' ) )->getFiles();
	// @phpstan-ignore phpstanApi.constructor, phpstanApi.constructor (Match locked CLI stub filtering, with real omission controls.)
	$stub_excluder = new PHPStan\File\FileExcluder( new PHPStan\File\FileHelper( $root ), $container->getParameter( 'stubFiles' ) );
	// @phpstan-ignore phpstanApi.method (Use the locked CLI exclusion semantics; requalify on upgrades.)
	$actual   = array_values( array_filter( $actual, static fn ( string $file ): bool => ! $stub_excluder->isExcludedFromAnalysing( $file ) ) );
	$expected = array_map( static fn ( string $file ): string => $root . '/' . $file, array_diff( array_unique( $selected_files ), $files ) );
	if ( array() !== array_diff( $expected, $actual ) || array() !== array_diff( $actual, $expected ) ) {
		throw new RuntimeException( 'Effective development PHPStan selection differs from maintained development PHP.' );
	}
} finally {
	if ( is_dir( $temp ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $temp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $entry ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the private analyzer container cache created above.
			$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the private analyzer container cache created above.
		rmdir( $temp );
	}
}

printf( "Maintained PHP coverage: %d product files analysed; %d maintained PHP-bearing files checked by PHPCS/PHPCBF and directly analysed at level 8.\n", count( $files ), count( array_unique( $selected_files ) ) );
