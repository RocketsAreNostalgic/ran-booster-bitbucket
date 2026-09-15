<?php

declare(strict_types=1);

$coreRoot = getenv( 'RAN_BOOSTER_CORE_PATH' );
$coreRoot = false === $coreRoot || '' === $coreRoot
	? dirname( __DIR__ ) . '/../ran-booster'
	: rtrim( $coreRoot, '/\\' );
$coreAutoload = $coreRoot . '/autoload.php';

if ( ! is_file( $coreAutoload ) ) {
	throw new RuntimeException(
		'RAN Booster Bitbucket tests require the certified RAN Booster production source. '
		. 'Set RAN_BOOSTER_CORE_PATH to the exact certified Core checkout.'
	);
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require $coreAutoload;
require dirname( __DIR__ ) . '/autoload.php';

if ( ! function_exists( 'add_action' ) ) {
	/** @param callable $callback */
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['ran_booster_bitbucket_test_actions'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text ): string {
		return $text;
	}
}
require __DIR__ . '/RepositoryProvider/BitbucketCredentialValidationSecretsStub.php';
require __DIR__ . '/RepositoryProvider/BitbucketCredentialValidationTransportError.php';
require __DIR__ . '/RepositoryProvider/BitbucketProviderCredentialStore.php';
require __DIR__ . '/RepositoryProvider/BitbucketRepositoryBrowserSecretsStub.php';
