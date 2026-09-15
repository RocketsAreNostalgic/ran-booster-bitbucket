<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/certified-core-checkout.php';

$coreRoot     = ran_booster_bitbucket_certified_core_root();
$coreAutoload = $coreRoot . '/autoload.php';

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
