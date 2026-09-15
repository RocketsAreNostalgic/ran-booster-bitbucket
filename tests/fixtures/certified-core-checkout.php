<?php

declare(strict_types=1);

function ran_booster_bitbucket_certified_core_root(): string {
	$repositoryRoot = dirname( __DIR__, 2 );
	$composerPath   = $repositoryRoot . '/composer.json';
	$composerJson   = file_get_contents( $composerPath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local certification fixture.

	if ( ! is_string( $composerJson ) ) {
		throw new RuntimeException( 'Unable to read the Bitbucket Composer certification record.' );
	}

	$composer      = json_decode( $composerJson, true, 512, JSON_THROW_ON_ERROR );
	$certification = $composer['extra']['ran-booster-core-certification'] ?? null;
	$expectedTag   = is_array( $certification ) ? ( $certification['tag'] ?? null ) : null;
	$expectedCommit = is_array( $certification ) ? ( $certification['commit'] ?? null ) : null;

	if ( ! is_string( $expectedTag )
		|| 1 !== preg_match( '/^v[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $expectedTag )
		|| ! is_string( $expectedCommit )
		|| 1 !== preg_match( '/^[0-9a-f]{40}$/', $expectedCommit )
	) {
		throw new RuntimeException( 'The Bitbucket Core certification record is invalid.' );
	}

	$coreRoot = getenv( 'RAN_BOOSTER_CORE_PATH' );
	$coreRoot = false === $coreRoot || '' === $coreRoot
		? $repositoryRoot . '/../ran-booster'
		: rtrim( $coreRoot, '/\\' );
	$coreAutoload = $coreRoot . '/autoload.php';

	if ( ! is_file( $coreAutoload ) ) {
		throw new RuntimeException(
			'RAN Booster Bitbucket requires the certified RAN Booster production source. '
			. 'Set RAN_BOOSTER_CORE_PATH to the exact certified Core checkout.'
		);
	}

	$actualCommit = shell_exec( 'git -C ' . escapeshellarg( $coreRoot ) . ' rev-parse HEAD' );
	if ( ! is_string( $actualCommit ) || $expectedCommit !== trim( $actualCommit ) ) {
		throw new RuntimeException(
			'RAN Booster Bitbucket requires the exact certified Core checkout '
			. $expectedTag . ' at ' . $expectedCommit . '.'
		);
	}

	return $coreRoot;
}
