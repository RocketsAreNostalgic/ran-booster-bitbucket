<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.

declare(strict_types=1);


const RAN_BOOSTER_BITBUCKET_PACKAGE_ROOT             = 'ran-booster-bitbucket/';
const RAN_BOOSTER_BITBUCKET_MAX_ARCHIVE_MEMBERS      = 256;
const RAN_BOOSTER_BITBUCKET_MAX_MEMBER_BYTES         = 5_242_880;
const RAN_BOOSTER_BITBUCKET_MAX_UNCOMPRESSED_BYTES   = 20_971_520;
const RAN_BOOSTER_BITBUCKET_MAX_COMPRESSED_BYTES     = 10_485_760;
const RAN_BOOSTER_BITBUCKET_MAX_COMPRESSION_RATIO    = 100;
const RAN_BOOSTER_BITBUCKET_CANONICAL_REPOSITORY_URL = 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket';

function ran_booster_bitbucket_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to its standard stream without WordPress.
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

/** @param list<string> $arguments */
function ran_booster_bitbucket_git( array $arguments ): string {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- CLI contract controls a disposable subprocess and inspects its exact exit status.
	$process = proc_open(
		array_merge( array( 'git' ), $arguments ),
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);

	if ( ! is_resource( $process ) ) {
		ran_booster_bitbucket_fail( 'Could not start Git.' );
	}

	$output        = stream_get_contents( $pipes[1] );
	$fixture_error = stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CLI subprocess pipe after collecting its output.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CLI subprocess pipe after collecting its output.
	fclose( $pipes[2] );
	$status = proc_close( $process );

	if ( 0 !== $status || false === $output ) {
		ran_booster_bitbucket_fail( 'Git source inspection failed: ' . trim( (string) $fixture_error ) );
	}

	return $output;
}

function ran_booster_bitbucket_normalize_member_name( string $name ): string {
	if ( '' === $name
		|| str_contains( $name, "\0" )
		|| str_contains( $name, '\\' )
		|| str_starts_with( $name, '/' )
		|| ! str_starts_with( $name, RAN_BOOSTER_BITBUCKET_PACKAGE_ROOT )
	) {
		ran_booster_bitbucket_fail( 'Archive contains an unsafe member name.' );
	}

	$is_directory = str_ends_with( $name, '/' );
	$fixture_path = $is_directory ? substr( $name, 0, -1 ) : $name;
	$segments     = explode( '/', $fixture_path );

	foreach ( $segments as $segment ) {
		if ( '' === $segment || '.' === $segment || '..' === $segment ) {
			ran_booster_bitbucket_fail( 'Archive contains a non-canonical member name.' );
		}
	}

	return implode( '/', $segments ) . ( $is_directory ? '/' : '' );
}

function ran_booster_bitbucket_source_files( string $commit ): array {
	$allowlist = preg_split( '/\R/', ran_booster_bitbucket_git( array( 'show', $commit . ':release-files.txt' ) ) );
	if ( false === $allowlist ) {
		ran_booster_bitbucket_fail( 'Could not parse the release allowlist.' );
	}

	$files = array();
	foreach ( $allowlist as $fixture_path ) {
		$fixture_path = trim( $fixture_path );
		if ( '' === $fixture_path || str_starts_with( $fixture_path, '#' ) ) {
			continue;
		}

		$listed = preg_split( '/\R/', trim( ran_booster_bitbucket_git( array( 'ls-tree', '-r', '--name-only', $commit, '--', $fixture_path ) ) ) );
		foreach ( false === $listed ? array() : $listed as $file ) {
			if ( '' !== $file ) {
				$files[ RAN_BOOSTER_BITBUCKET_PACKAGE_ROOT . $file ] = ran_booster_bitbucket_git( array( 'show', $commit . ':' . $file ) );
			}
		}
	}

	ksort( $files, SORT_STRING );
	return $files;
}

function ran_booster_bitbucket_expected_directories( array $files ): array {
	$directories = array( RAN_BOOSTER_BITBUCKET_PACKAGE_ROOT => true );
	foreach ( array_keys( $files ) as $file ) {
		$directory = dirname( $file );
		while ( '.' !== $directory && ! isset( $directories[ $directory . '/' ] ) ) {
			$directories[ $directory . '/' ] = true;
			$directory                       = dirname( $directory );
		}
	}

	return $directories;
}

$archive       = $argv[1] ?? '';
$source_commit = $argv[2] ?? '';
$root          = dirname( __DIR__ );
chdir( $root );

if ( 1 !== preg_match( '/^[0-9a-f]{40}$/', $source_commit )
	|| trim( ran_booster_bitbucket_git( array( 'rev-parse', $source_commit . '^{commit}' ) ) ) !== $source_commit
) {
	ran_booster_bitbucket_fail( 'Source commit must be an existing full commit ID.' );
}

if ( ! str_starts_with( $archive, '/' ) ) {
	$archive = $root . '/' . $archive;
}
if ( ! is_file( $archive ) ) {
	ran_booster_bitbucket_fail( 'Release archive is missing.' );
}

$checksum = $archive . '.sha256';
if ( ! is_file( $checksum ) ) {
	ran_booster_bitbucket_fail( 'Release checksum is missing.' );
}
$archive_name    = basename( $archive );
$expected_digest = hash_file( 'sha256', $archive ) . '  ' . $archive_name;
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local repository or archive bytes in standalone CLI tooling; no remote HTTP request.
$recorded_digest = rtrim( (string) file_get_contents( $checksum ), "\r\n" );
if ( $recorded_digest !== $expected_digest ) {
	ran_booster_bitbucket_fail( 'Release checksum does not match the archive and basename.' );
}

$source_plugin   = ran_booster_bitbucket_git( array( 'show', $source_commit . ':ran-booster-bitbucket.php' ) );
$source_readme   = ran_booster_bitbucket_git( array( 'show', $source_commit . ':readme.txt' ) );
$source_composer = json_decode( ran_booster_bitbucket_git( array( 'show', $source_commit . ':composer.json' ) ), true, 512, JSON_THROW_ON_ERROR );
if ( ( $source_composer['support']['source'] ?? null ) !== RAN_BOOSTER_BITBUCKET_CANONICAL_REPOSITORY_URL
	|| ! str_contains( $source_plugin, 'Plugin URI: ' . RAN_BOOSTER_BITBUCKET_CANONICAL_REPOSITORY_URL )
	|| ! str_contains( $source_plugin, 'Update URI: ' . RAN_BOOSTER_BITBUCKET_CANONICAL_REPOSITORY_URL )
) {
	ran_booster_bitbucket_fail( 'Source repository and Update URI identity are inconsistent.' );
}

preg_match( '/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+)[[:space:]]*$/m', $source_plugin, $plugin_version );
preg_match( '/^Stable tag:[[:space:]]*([^[:space:]]+)[[:space:]]*$/m', $source_readme, $readme_version );
if ( '' === ( $plugin_version[1] ?? '' ) || ( $readme_version[1] ?? null ) !== $plugin_version[1] ) {
	ran_booster_bitbucket_fail( 'Source plugin header and readme version must match.' );
}
if ( 'ran-booster-bitbucket-' . $plugin_version[1] . '.zip' !== $archive_name ) {
	ran_booster_bitbucket_fail( 'Archive filename does not match the source version.' );
}

$expected_files                             = ran_booster_bitbucket_source_files( $source_commit );
$ran_booster_bitbucket_expected_directories = ran_booster_bitbucket_expected_directories( $expected_files );
$zip                                        = new ZipArchive();
if ( true !== $zip->open( $archive, ZipArchive::RDONLY ) ) {
	ran_booster_bitbucket_fail( 'Release archive could not be opened.' );
}
// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve the native DOM/ZipArchive property spelling; this receiver is not owned.
if ( $zip->numFiles < 1 || $zip->numFiles > RAN_BOOSTER_BITBUCKET_MAX_ARCHIVE_MEMBERS ) {
	ran_booster_bitbucket_fail( 'Archive member count exceeds the safe bound.' );
}

$seen_raw           = array();
$seen_normalized    = array();
$actual_files       = array();
$actual_directories = array();
$compressed_bytes   = 0;
$uncompressed_bytes = 0;

// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve the native DOM/ZipArchive property spelling; this receiver is not owned.
for ( $index = 0; $index < $zip->numFiles; ++$index ) {
	$stat = $zip->statIndex( $index, ZipArchive::FL_UNCHANGED );
	$name = $zip->getNameIndex( $index, ZipArchive::FL_UNCHANGED );
	if ( false === $stat || false === $name || isset( $seen_raw[ $name ] ) ) {
		ran_booster_bitbucket_fail( 'Archive contains duplicate or unreadable raw members.' );
	}
	$seen_raw[ $name ] = true;
	$normalized        = ran_booster_bitbucket_normalize_member_name( $name );
	if ( isset( $seen_normalized[ $normalized ] ) ) {
		ran_booster_bitbucket_fail( 'Archive contains normalized member collisions.' );
	}
	$seen_normalized[ $normalized ] = true;

	$size       = (int) $stat['size'];
	$compressed = (int) $stat['comp_size'];
	$method     = (int) $stat['comp_method'];
	if ( $size < 0 || $compressed < 0 || $size > RAN_BOOSTER_BITBUCKET_MAX_MEMBER_BYTES
		|| ! in_array( $method, array( ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE ), true )
		|| 0 !== (int) $stat['encryption_method']
		|| ( 0 < $compressed && $size > $compressed * RAN_BOOSTER_BITBUCKET_MAX_COMPRESSION_RATIO )
		|| ( 0 === $compressed && 0 < $size )
	) {
		ran_booster_bitbucket_fail( 'Archive member exceeds the permitted type, encryption, size or ratio bounds.' );
	}
	$compressed_bytes   += $compressed;
	$uncompressed_bytes += $size;
	if ( $compressed_bytes > RAN_BOOSTER_BITBUCKET_MAX_COMPRESSED_BYTES || $uncompressed_bytes > RAN_BOOSTER_BITBUCKET_MAX_UNCOMPRESSED_BYTES ) {
		ran_booster_bitbucket_fail( 'Archive aggregate size exceeds the safe bound.' );
	}

	$operations = 0;
	$attributes = 0;
	if ( ! $zip->getExternalAttributesIndex( $index, $operations, $attributes, ZipArchive::FL_UNCHANGED ) ) {
		ran_booster_bitbucket_fail( 'Archive member attributes are unreadable.' );
	}
	$fixture_mode = ( $attributes >> 16 ) & 0xffff;
	$is_directory = str_ends_with( $name, '/' );
	if ( ZipArchive::OPSYS_UNIX === $operations
		&& ( $is_directory ? 0040755 !== $fixture_mode : 0100644 !== $fixture_mode )
	) {
		ran_booster_bitbucket_fail( 'Archive contains a symlink, non-regular or executable member.' );
	}
	if ( ZipArchive::OPSYS_UNIX !== $operations && 0 !== $fixture_mode ) {
		ran_booster_bitbucket_fail( 'Archive contains untrusted non-Unix mode metadata.' );
	}

	if ( $is_directory ) {
		$actual_directories[ $normalized ] = true;
		continue;
	}

	$content = $zip->getFromIndex( $index, $size, ZipArchive::FL_UNCHANGED );
	if ( false === $content || strlen( $content ) !== $size ) {
		ran_booster_bitbucket_fail( 'Archive member could not be read completely.' );
	}
	$actual_files[ $normalized ] = $content;
}
$zip->close();

ksort( $actual_files, SORT_STRING );
ksort( $actual_directories, SORT_STRING );
ksort( $ran_booster_bitbucket_expected_directories, SORT_STRING );
if ( array_keys( $actual_files ) !== array_keys( $expected_files )
	|| array_keys( $actual_directories ) !== array_keys( $ran_booster_bitbucket_expected_directories )
) {
	ran_booster_bitbucket_fail( 'Archive contents do not exactly match the source allowlist.' );
}
foreach ( $expected_files as $name => $content ) {
	if ( ! hash_equals( hash( 'sha256', $content ), hash( 'sha256', $actual_files[ $name ] ) ) ) {
		ran_booster_bitbucket_fail( 'Archive member bytes do not match the source commit.' );
	}
}

$fixture_plugin = $actual_files[ RAN_BOOSTER_BITBUCKET_PACKAGE_ROOT . 'ran-booster-bitbucket.php' ];
$guide          = $actual_files[ RAN_BOOSTER_BITBUCKET_PACKAGE_ROOT . 'views/documentation.php' ];
$security       = $actual_files[ RAN_BOOSTER_BITBUCKET_PACKAGE_ROOT . 'SECURITY.md' ];
$composition    = $actual_files[ RAN_BOOSTER_BITBUCKET_PACKAGE_ROOT . 'src/Bitbucket/Plugin.php' ];
if ( ! str_contains( $fixture_plugin, '\\RAN\\Booster\\Bitbucket\\Plugin::boot();' )
	|| ! str_contains( $composition, 'RAN_BOOSTER_PROVIDER_API_VERSION' )
	|| ! str_contains( $composition, '14 === RAN_BOOSTER_PROVIDER_API_VERSION' )
	|| ! str_contains( $composition, 'RAN_BOOSTER_ADDON_API_VERSION' )
	|| ! str_contains( $composition, '17 === RAN_BOOSTER_ADDON_API_VERSION' )
	|| ! str_contains( $composition, 'ProviderRegistrationContext $registration_context' )
	|| ! str_contains( $composition, 'ran_booster_documentation_sections_after_provider_bb' )
	|| ! str_contains( $composition, 'ran-booster-documentation-bitbucket-cloud' )
	|| str_contains( $fixture_plugin . $composition, 'RAN_BOOSTER_LOGGING_API_VERSION' )
) {
	ran_booster_bitbucket_fail( 'Archive does not preserve the Bitbucket composition and API contract.' );
}
if ( ! str_contains( $security, '/security/advisories/new' ) ) {
	ran_booster_bitbucket_fail( 'Archive security policy does not name the private reporting route.' );
}

foreach ( array(
	'Repositories: Read (read:repository:bitbucket)',
	'Set up Push-to-Deploy manually',
	'Move or recover a package with Transporter',
	'There is no credential or anonymous fallback',
	'does not remove, revoke or rotate the source API token',
	'Deactivation, deletion and provider cleanup',
	'Releases and support',
) as $required_guide_text ) {
	if ( ! str_contains( $guide, $required_guide_text ) ) {
		ran_booster_bitbucket_fail( 'Archive Bitbucket guide is incomplete.' );
	}
}
if ( 1 === preg_match( '/<(form|input|button)([[:space:]>])|wp_nonce|admin_post_/', $guide ) ) {
	ran_booster_bitbucket_fail( 'Archive Bitbucket guide must remain non-interactive.' );
}

$temporary = sys_get_temp_dir() . '/ran-booster-bitbucket-verify-' . bin2hex( random_bytes( 8 ) );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create a disposable local CLI fixture directory without loading WordPress.
if ( ! mkdir( $temporary, 0700, true ) ) {
	ran_booster_bitbucket_fail( 'Could not create the syntax-check directory.' );
}
register_shutdown_function(
	static function () use ( $temporary ): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $temporary, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the disposable local CLI fixture directory without loading WordPress. Remove only the disposable local CLI fixture file without loading WordPress.
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the disposable local CLI fixture directory without loading WordPress.
		rmdir( $temporary );
	}
);
foreach ( $actual_files as $name => $content ) {
	if ( ! str_ends_with( $name, '.php' ) ) {
		continue;
	}
	$fixture_path = $temporary . '/' . substr( $name, strlen( RAN_BOOSTER_BITBUCKET_PACKAGE_ROOT ) );
	if ( ! is_dir( dirname( $fixture_path ) ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create a disposable local CLI fixture directory without loading WordPress.
		mkdir( dirname( $fixture_path ), 0700, true );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write a disposable CLI verifier fixture without WordPress.
	file_put_contents( $fixture_path, $content );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- CLI contract controls a disposable subprocess and inspects its exact exit status.
	$process = proc_open(
		array( PHP_BINARY, '-l', $fixture_path ),
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);
	if ( ! is_resource( $process ) ) {
		ran_booster_bitbucket_fail( 'Could not start PHP syntax verification.' );
	}
	stream_get_contents( $pipes[1] );
	$fixture_error = stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CLI subprocess pipe after collecting its output.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CLI subprocess pipe after collecting its output.
	fclose( $pipes[2] );
	if ( 0 !== proc_close( $process ) ) {
		ran_booster_bitbucket_fail( 'Archive PHP syntax failed: ' . trim( (string) $fixture_error ) );
	}
}
