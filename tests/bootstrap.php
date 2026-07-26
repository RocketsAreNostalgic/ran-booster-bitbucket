<?php

declare(strict_types=1);

$coreRoot = getenv( 'RAN_BOOSTER_CORE_PATH' );
$coreRoot = false === $coreRoot || '' === $coreRoot
	? dirname( __DIR__ ) . '/../ran-booster'
	: rtrim( $coreRoot, '/\\' );
$coreAutoload = $coreRoot . '/vendor/autoload.php';

if ( ! is_file( $coreAutoload ) ) {
	throw new RuntimeException(
		'RAN Booster Bitbucket tests require a compatible sibling RAN Booster checkout with Composer dependencies. '
		. 'Set RAN_BOOSTER_CORE_PATH or run composer install in ' . $coreRoot . '.'
	);
}

require $coreAutoload;
require dirname( __DIR__ ) . '/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

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
require __DIR__ . '/RepositoryProvider/BitbucketLoggingStub.php';
require __DIR__ . '/RepositoryProvider/BitbucketProviderCredentialStore.php';
require __DIR__ . '/RepositoryProvider/BitbucketRepositoryBrowserSecretsStub.php';
