<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';

if ( ! in_array( $mode, array( 'absent', 'incompatible', 'compatible', 'inactive' ), true ) ) {
	fwrite( STDERR, "A valid lifecycle mode is required.\n" );
	exit( 2 );
}

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['ran_booster_bitbucket_fixture_actions'] = array();

/** @param callable $callback */
function add_action( string $hook, callable $callback ): void {
	$GLOBALS['ran_booster_bitbucket_fixture_actions'][ $hook ][] = $callback;
}

function esc_html__( string $text ): string {
	return $text;
}

if ( 'incompatible' === $mode ) {
	define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 4 );
}

if ( 'compatible' === $mode ) {
	$coreRoot = getenv( 'RAN_BOOSTER_CORE_PATH' );
	$coreRoot = false === $coreRoot || '' === $coreRoot
		? dirname( __DIR__, 3 ) . '/ran-booster'
		: rtrim( $coreRoot, '/\\' );
	$coreAutoload = $coreRoot . '/vendor/autoload.php';

	if ( ! is_file( $coreAutoload ) ) {
		fwrite( STDERR, "A compatible RAN Booster sibling checkout is required.\n" );
		exit( 3 );
	}

	require $coreAutoload;
	define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 5 );
	define( 'RAN_BOOSTER_LOGGING_API_VERSION', 1 );
}

if ( 'inactive' !== $mode ) {
	require dirname( __DIR__, 2 ) . '/ran-booster-bitbucket.php';
}

$callbacks = $GLOBALS['ran_booster_bitbucket_fixture_actions']['ran_booster_register_providers'] ?? array();
$result    = array(
	'provider_callbacks'           => count( $callbacks ),
	'provider_loaded'              => class_exists( 'RAN\\Booster\\Bitbucket\\BitbucketProvider', false ),
	'registered'                   => false,
	'provider_code'                => '',
	'credential_store_was_scoped'  => false,
	'implements_release_catalog'   => false,
	'remote_calls'                 => 0,
);

if ( 'compatible' === $mode ) {
	$store = new class() implements \RAN\RepositoryProvider\ProviderCredentialStore {
		public function credentialProfiles(): array {
			return array();
		}

	public function credentialMaterial( ?string $id = null ): ?array {
		return null;
	}

	public function hasWebhookProfile(): bool {
		return false;
	}
};
	$registry = new \RAN\RepositoryProvider\ProviderRegistry(
		array(),
		new \RAN\RepositoryProvider\ProviderSecretPolicyCatalog(),
		static function ( \RAN\RepositoryProvider\ProviderCode $code ) use ( $store, &$result ): \RAN\RepositoryProvider\ProviderCredentialStore {
			$result['credential_store_was_scoped'] = 'bb' === $code->value;

			return $store;
		}
	);

	$callbacks[0]( $registry );
	$provider                             = $registry->get( 'bb' );
	$result['registered']                 = true;
	$result['provider_code']              = $provider->getMetadata()->code->value;
	$result['implements_release_catalog'] = $provider instanceof \RAN\RepositoryProvider\ReleaseCatalog;
} elseif ( array() !== $callbacks ) {
	$callbacks[0]( new stdClass() );
}

echo json_encode( $result, JSON_THROW_ON_ERROR );
