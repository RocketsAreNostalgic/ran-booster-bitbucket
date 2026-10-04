<?php

declare(strict_types=1);

function ran_booster_bitbucket_certified_core_root(): string {
	$repository_root = dirname( __DIR__, 2 );
	$composer_path   = $repository_root . '/composer.json';
	$composer_json   = file_get_contents( $composer_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local certification fixture.

	if ( ! is_string( $composer_json ) ) {
		throw new RuntimeException( 'Unable to read the Bitbucket Composer certification record.' );
	}

	$composer                       = json_decode( $composer_json, true, 512, JSON_THROW_ON_ERROR );
	$certification                  = $composer['extra']['ran-booster-core-certification'] ?? null;
	$expected_tag                   = is_array( $certification ) ? ( $certification['tag'] ?? null ) : null;
	$expected_commit                = is_array( $certification ) ? ( $certification['commit'] ?? null ) : null;
	$expected_archive_source_commit = is_array( $certification ) ? ( $certification['archive-source-commit'] ?? null ) : null;

	if ( ! is_string( $expected_tag )
		|| 1 !== preg_match( '/^v[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $expected_tag )
		|| ! is_string( $expected_commit )
		|| 1 !== preg_match( '/^[0-9a-f]{40}$/', $expected_commit )
		|| ! is_string( $expected_archive_source_commit )
		|| 1 !== preg_match( '/^[0-9a-f]{40}$/', $expected_archive_source_commit )
	) {
		throw new RuntimeException( 'The Bitbucket Core certification record is invalid.' );
	}

	$mode = getenv( 'RAN_BOOSTER_CORE_TEST_MODE' );
	if ( false !== $mode && '' !== $mode && 'candidate' !== $mode ) {
		throw new RuntimeException( 'Unsupported Core test mode.' );
	}
	if ( 'candidate' === $mode ) {
		$candidate = $composer['extra']['ran-booster-core-candidate']['commit'] ?? null;
		if ( ! is_string( $candidate ) || 1 !== preg_match( '/^[0-9a-f]{40}$/', $candidate ) ) {
			throw new RuntimeException( 'The Core candidate source identity is invalid.' );
		}
		$expected_commit = $candidate;
		$expected_tag    = 'unreleased source candidate';
	}

	$core_root     = getenv( 'RAN_BOOSTER_CORE_PATH' );
	$core_root     = false === $core_root || '' === $core_root
		? $repository_root . '/../ran-booster'
		: rtrim( $core_root, '/\\' );
	$core_autoload = $core_root . '/autoload.php';

	if ( ! is_file( $core_autoload ) ) {
		throw new RuntimeException(
			'RAN Booster Bitbucket requires the configured RAN Booster production source. '
			. 'Set RAN_BOOSTER_CORE_PATH to the exact configured Core checkout.'
		);
	}

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Isolated CLI contract executes repository tooling in a disposable subprocess, outside WordPress runtime.
	$actual_commit = shell_exec( 'git -C ' . escapeshellarg( $core_root ) . ' rev-parse HEAD' );
	if ( ! is_string( $actual_commit ) || trim( $actual_commit ) !== $expected_commit ) {
		throw new RuntimeException(
			'RAN Booster Bitbucket requires the exact configured Core checkout '
			. $expected_tag . ' at ' . $expected_commit . '.'
		);
	}

	return $core_root;
}
