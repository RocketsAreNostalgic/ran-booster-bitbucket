<?php

declare(strict_types=1);

// phpcs:disable -- Standalone release verifier; it deliberately uses CLI filesystem and process APIs.

const PACKAGE_ROOT             = 'ran-booster-bitbucket/';
const MAX_ARCHIVE_MEMBERS      = 256;
const MAX_MEMBER_BYTES         = 5_242_880;
const MAX_UNCOMPRESSED_BYTES   = 20_971_520;
const MAX_COMPRESSED_BYTES     = 10_485_760;
const MAX_COMPRESSION_RATIO    = 100;
const CANONICAL_REPOSITORY_URL = 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket';

function fail( string $message ): never {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

/** @param list<string> $arguments */
function git( array $arguments ): string {
	$process = proc_open(
		array_merge( array( 'git' ), $arguments ),
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);

	if ( ! is_resource( $process ) ) {
		fail( 'Could not start Git.' );
	}

	$output = stream_get_contents( $pipes[1] );
	$error  = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$status = proc_close( $process );

	if ( 0 !== $status || false === $output ) {
		fail( 'Git source inspection failed: ' . trim( (string) $error ) );
	}

	return $output;
}

function normalizeMemberName( string $name ): string {
	if ( '' === $name
		|| str_contains( $name, "\0" )
		|| str_contains( $name, '\\' )
		|| str_starts_with( $name, '/' )
		|| ! str_starts_with( $name, PACKAGE_ROOT )
	) {
		fail( 'Archive contains an unsafe member name.' );
	}

	$isDirectory = str_ends_with( $name, '/' );
	$path        = $isDirectory ? substr( $name, 0, -1 ) : $name;
	$segments    = explode( '/', $path );

	foreach ( $segments as $segment ) {
		if ( '' === $segment || '.' === $segment || '..' === $segment ) {
			fail( 'Archive contains a non-canonical member name.' );
		}
	}

	return implode( '/', $segments ) . ( $isDirectory ? '/' : '' );
}

function sourceFiles( string $commit ): array {
	$allowlist = preg_split( '/\R/', git( array( 'show', $commit . ':release-files.txt' ) ) );
	if ( false === $allowlist ) {
		fail( 'Could not parse the release allowlist.' );
	}

	$files = array();
	foreach ( $allowlist as $path ) {
		$path = trim( $path );
		if ( '' === $path || str_starts_with( $path, '#' ) ) {
			continue;
		}

		$listed = preg_split( '/\R/', trim( git( array( 'ls-tree', '-r', '--name-only', $commit, '--', $path ) ) ) );
		foreach ( false === $listed ? array() : $listed as $file ) {
			if ( '' !== $file ) {
				$files[ PACKAGE_ROOT . $file ] = git( array( 'show', $commit . ':' . $file ) );
			}
		}
	}

	ksort( $files, SORT_STRING );
	return $files;
}

function expectedDirectories( array $files ): array {
	$directories = array( PACKAGE_ROOT => true );
	foreach ( array_keys( $files ) as $file ) {
		$directory = dirname( $file );
		while ( '.' !== $directory && ! isset( $directories[ $directory . '/' ] ) ) {
			$directories[ $directory . '/' ] = true;
			$directory = dirname( $directory );
		}
	}

	return $directories;
}

$archive      = $argv[1] ?? '';
$sourceCommit = $argv[2] ?? '';
$root         = dirname( __DIR__ );
chdir( $root );

if ( 1 !== preg_match( '/^[0-9a-f]{40}$/', $sourceCommit )
	|| trim( git( array( 'rev-parse', $sourceCommit . '^{commit}' ) ) ) !== $sourceCommit
) {
	fail( 'Source commit must be an existing full commit ID.' );
}

if ( ! str_starts_with( $archive, '/' ) ) {
	$archive = $root . '/' . $archive;
}
if ( ! is_file( $archive ) ) {
	fail( 'Release archive is missing.' );
}

$checksum = $archive . '.sha256';
if ( ! is_file( $checksum ) ) {
	fail( 'Release checksum is missing.' );
}
$archiveName     = basename( $archive );
$expectedDigest  = hash_file( 'sha256', $archive ) . '  ' . $archiveName;
$recordedDigest  = rtrim( (string) file_get_contents( $checksum ), "\r\n" );
if ( $recordedDigest !== $expectedDigest ) {
	fail( 'Release checksum does not match the archive and basename.' );
}

$sourcePlugin = git( array( 'show', $sourceCommit . ':ran-booster-bitbucket.php' ) );
$sourceReadme = git( array( 'show', $sourceCommit . ':readme.txt' ) );
$sourceComposer = json_decode( git( array( 'show', $sourceCommit . ':composer.json' ) ), true, 512, JSON_THROW_ON_ERROR );
if ( ( $sourceComposer['support']['source'] ?? null ) !== CANONICAL_REPOSITORY_URL
	|| ! str_contains( $sourcePlugin, 'Plugin URI: ' . CANONICAL_REPOSITORY_URL )
	|| ! str_contains( $sourcePlugin, 'Update URI: ' . CANONICAL_REPOSITORY_URL )
) {
	fail( 'Source repository and Update URI identity are inconsistent.' );
}

preg_match( '/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+)[[:space:]]*$/m', $sourcePlugin, $pluginVersion );
preg_match( '/^Stable tag:[[:space:]]*([^[:space:]]+)[[:space:]]*$/m', $sourceReadme, $readmeVersion );
if ( '' === ( $pluginVersion[1] ?? '' ) || ( $pluginVersion[1] ?? null ) !== ( $readmeVersion[1] ?? null ) ) {
	fail( 'Source plugin header and readme version must match.' );
}
if ( $archiveName !== 'ran-booster-bitbucket-' . $pluginVersion[1] . '.zip' ) {
	fail( 'Archive filename does not match the source version.' );
}

$expectedFiles       = sourceFiles( $sourceCommit );
$expectedDirectories = expectedDirectories( $expectedFiles );
$zip                 = new ZipArchive();
if ( true !== $zip->open( $archive, ZipArchive::RDONLY ) ) {
	fail( 'Release archive could not be opened.' );
}
if ( $zip->numFiles < 1 || $zip->numFiles > MAX_ARCHIVE_MEMBERS ) {
	fail( 'Archive member count exceeds the safe bound.' );
}

$seenRaw           = array();
$seenNormalized    = array();
$actualFiles       = array();
$actualDirectories = array();
$compressedBytes   = 0;
$uncompressedBytes = 0;

for ( $index = 0; $index < $zip->numFiles; ++$index ) {
	$stat = $zip->statIndex( $index, ZipArchive::FL_UNCHANGED );
	$name = $zip->getNameIndex( $index, ZipArchive::FL_UNCHANGED );
	if ( false === $stat || false === $name || isset( $seenRaw[ $name ] ) ) {
		fail( 'Archive contains duplicate or unreadable raw members.' );
	}
	$seenRaw[ $name ] = true;
	$normalized       = normalizeMemberName( $name );
	if ( isset( $seenNormalized[ $normalized ] ) ) {
		fail( 'Archive contains normalized member collisions.' );
	}
	$seenNormalized[ $normalized ] = true;

	$size       = (int) $stat['size'];
	$compressed = (int) $stat['comp_size'];
	$method     = (int) $stat['comp_method'];
	if ( $size < 0 || $compressed < 0 || $size > MAX_MEMBER_BYTES
		|| ! in_array( $method, array( ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE ), true )
		|| 0 !== (int) $stat['encryption_method']
		|| ( 0 < $compressed && $size > $compressed * MAX_COMPRESSION_RATIO )
		|| ( 0 === $compressed && 0 < $size )
	) {
		fail( 'Archive member exceeds the permitted type, encryption, size or ratio bounds.' );
	}
	$compressedBytes   += $compressed;
	$uncompressedBytes += $size;
	if ( $compressedBytes > MAX_COMPRESSED_BYTES || $uncompressedBytes > MAX_UNCOMPRESSED_BYTES ) {
		fail( 'Archive aggregate size exceeds the safe bound.' );
	}

	$operations = 0;
	$attributes = 0;
	if ( ! $zip->getExternalAttributesIndex( $index, $operations, $attributes, ZipArchive::FL_UNCHANGED ) ) {
		fail( 'Archive member attributes are unreadable.' );
	}
	$mode        = ( $attributes >> 16 ) & 0xffff;
	$isDirectory = str_ends_with( $name, '/' );
	if ( ZipArchive::OPSYS_UNIX === $operations
		&& ( $isDirectory ? 0040755 !== $mode : 0100644 !== $mode )
	) {
		fail( 'Archive contains a symlink, non-regular or executable member.' );
	}
	if ( ZipArchive::OPSYS_UNIX !== $operations && 0 !== $mode ) {
		fail( 'Archive contains untrusted non-Unix mode metadata.' );
	}

	if ( $isDirectory ) {
		$actualDirectories[ $normalized ] = true;
		continue;
	}

	$content = $zip->getFromIndex( $index, $size, ZipArchive::FL_UNCHANGED );
	if ( false === $content || strlen( $content ) !== $size ) {
		fail( 'Archive member could not be read completely.' );
	}
	$actualFiles[ $normalized ] = $content;
}
$zip->close();

ksort( $actualFiles, SORT_STRING );
ksort( $actualDirectories, SORT_STRING );
ksort( $expectedDirectories, SORT_STRING );
if ( array_keys( $actualFiles ) !== array_keys( $expectedFiles )
	|| array_keys( $actualDirectories ) !== array_keys( $expectedDirectories )
) {
	fail( 'Archive contents do not exactly match the source allowlist.' );
}
foreach ( $expectedFiles as $name => $content ) {
	if ( ! hash_equals( hash( 'sha256', $content ), hash( 'sha256', $actualFiles[ $name ] ) ) ) {
		fail( 'Archive member bytes do not match the source commit.' );
	}
}

$plugin = $actualFiles[ PACKAGE_ROOT . 'ran-booster-bitbucket.php' ];
$guide  = $actualFiles[ PACKAGE_ROOT . 'views/documentation.php' ];
$composition = $actualFiles[ PACKAGE_ROOT . 'src/Bitbucket/Plugin.php' ];
if ( ! str_contains( $plugin, '\\RAN\\Booster\\Bitbucket\\Plugin::boot();' )
	|| ! str_contains( $composition, 'RAN_BOOSTER_PROVIDER_API_VERSION' )
	|| ! str_contains( $composition, '10 === RAN_BOOSTER_PROVIDER_API_VERSION' )
	|| ! str_contains( $composition, 'RAN_BOOSTER_ADDON_API_VERSION' )
	|| ! str_contains( $composition, '15 === RAN_BOOSTER_ADDON_API_VERSION' )
	|| ! str_contains( $composition, 'ran_booster_documentation_sections_after_provider_bb' )
	|| ! str_contains( $composition, 'ran-booster-documentation-bitbucket-cloud' )
	|| str_contains( $plugin . $composition, 'RAN_BOOSTER_LOGGING_API_VERSION' )
) {
	fail( 'Archive does not preserve the Bitbucket composition and API contract.' );
}

foreach ( array(
	'Repositories: Read (read:repository:bitbucket)',
	'Set up Push-to-Deploy manually',
	'Move or recover a package with Transporter',
	'There is no credential or anonymous fallback',
	'does not remove, revoke or rotate the source API token',
	'Deactivation, deletion and provider cleanup',
	'Releases and support',
) as $requiredGuideText ) {
	if ( ! str_contains( $guide, $requiredGuideText ) ) {
		fail( 'Archive Bitbucket guide is incomplete.' );
	}
}
if ( 1 === preg_match( '/<(form|input|button)([[:space:]>])|wp_nonce|admin_post_/', $guide ) ) {
	fail( 'Archive Bitbucket guide must remain non-interactive.' );
}

$temporary = sys_get_temp_dir() . '/ran-booster-bitbucket-verify-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $temporary, 0700, true ) ) {
	fail( 'Could not create the syntax-check directory.' );
}
register_shutdown_function(
	static function () use ( $temporary ): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $temporary, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $temporary );
	}
);
foreach ( $actualFiles as $name => $content ) {
	if ( ! str_ends_with( $name, '.php' ) ) {
		continue;
	}
	$path = $temporary . '/' . substr( $name, strlen( PACKAGE_ROOT ) );
	if ( ! is_dir( dirname( $path ) ) ) {
		mkdir( dirname( $path ), 0700, true );
	}
	file_put_contents( $path, $content );
	$process = proc_open( array( PHP_BINARY, '-l', $path ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $process ) ) {
		fail( 'Could not start PHP syntax verification.' );
	}
	stream_get_contents( $pipes[1] );
	$error = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	if ( 0 !== proc_close( $process ) ) {
		fail( 'Archive PHP syntax failed: ' . trim( (string) $error ) );
	}
}
