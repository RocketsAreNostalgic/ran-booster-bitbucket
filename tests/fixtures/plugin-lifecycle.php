<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';

if ( ! in_array( $mode, array( 'absent', 'incompatible', 'incompatible-addon', 'incompatible-logging', 'compatible', 'unsupported-multisite', 'inactive' ), true ) ) {
	fwrite( STDERR, "A valid lifecycle mode is required.\n" );
	exit( 2 );
}

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['ran_booster_bitbucket_fixture_actions'] = array();

/** @param callable $callback */
function add_action( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): void {
	unset( $priority, $acceptedArgs );
	$GLOBALS['ran_booster_bitbucket_fixture_actions'][ $hook ][] = $callback;
}

function esc_html__( string $text ): string {
	return $text;
}

function esc_html_e( string $text ): void {
	echo $text;
}

function esc_url( string $url ): string {
	return $url;
}

function admin_url( string $path ): string {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

if ( 'incompatible' === $mode ) {
	define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 5 );
	define( 'RAN_BOOSTER_LOGGING_API_VERSION', 1 );
	define( 'RAN_BOOSTER_ADDON_API_VERSION', 7 );
}

if ( 'incompatible-addon' === $mode ) {
	define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 6 );
	define( 'RAN_BOOSTER_LOGGING_API_VERSION', 1 );
	define( 'RAN_BOOSTER_ADDON_API_VERSION', 6 );
}

if ( 'incompatible-logging' === $mode ) {
	define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 6 );
	define( 'RAN_BOOSTER_LOGGING_API_VERSION', 0 );
	define( 'RAN_BOOSTER_ADDON_API_VERSION', 7 );
}

if ( in_array( $mode, array( 'compatible', 'unsupported-multisite' ), true ) ) {
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
	define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 6 );
	define( 'RAN_BOOSTER_LOGGING_API_VERSION', 1 );
	define( 'RAN_BOOSTER_ADDON_API_VERSION', 7 );
}

if ( 'unsupported-multisite' === $mode ) {
	define( 'RAN_BOOSTER_RUNTIME_MODE', 'multisite_unsupported' );
}

if ( 'inactive' !== $mode ) {
	require dirname( __DIR__, 2 ) . '/ran-booster-bitbucket.php';
}

$callbacks = $GLOBALS['ran_booster_bitbucket_fixture_actions']['ran_booster_register_providers'] ?? array();
$documentationCallbacks = $GLOBALS['ran_booster_bitbucket_fixture_actions']['ran_booster_documentation_after_provider_bb'] ?? array();
$documentation = '';
if ( array() !== $documentationCallbacks ) {
	ob_start();
	$documentationCallbacks[0]( 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=documentation', 'site' );
	$documentation = (string) ob_get_clean();
}
$result    = array(
	'provider_callbacks'           => count( $callbacks ),
	'documentation_callbacks'      => count( $documentationCallbacks ),
	'documentation'                => $documentation,
	'provider_loaded'              => class_exists( 'RAN\\Booster\\Bitbucket\\BitbucketProvider', false ),
	'registered'                   => false,
	'provider_code'                => '',
	'credential_store_was_scoped'  => false,
	'implements_release_catalog'   => false,
	'remote_calls'                 => 0,
);

if ( in_array( $mode, array( 'compatible', 'unsupported-multisite' ), true ) ) {
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
	$logging = new class() implements \RAN\AddOn\Logging\LoggingFacade {
		public function log( string $message, array $context = array() ): void {
			unset( $message, $context );
		}

		public function logException( string $message, \Throwable $exception, array $context = array() ): void {
			unset( $message, $exception, $context );
		}
	};
	$registry = new \RAN\RepositoryProvider\ProviderRegistry(
		$logging,
		array(),
		new \RAN\RepositoryProvider\ProviderSecretPolicyCatalog(),
		static function ( \RAN\RepositoryProvider\ProviderCode $code ) use ( $store, &$result ): \RAN\RepositoryProvider\ProviderCredentialStore {
			$result['credential_store_was_scoped'] = 'bb' === $code->value;

			return $store;
		}
	);

	$callbacks[0]( $registry );
	$result['registered'] = array_key_exists( 'bb', $registry->all() );
	if ( $result['registered'] ) {
		$provider                             = $registry->get( 'bb' );
		$result['provider_code']              = $provider->getMetadata()->code->value;
		$result['implements_release_catalog'] = $provider instanceof \RAN\RepositoryProvider\ReleaseCatalog;
	}
} elseif ( array() !== $callbacks ) {
	$callbacks[0]( new stdClass() );
}

echo json_encode( $result, JSON_THROW_ON_ERROR );
