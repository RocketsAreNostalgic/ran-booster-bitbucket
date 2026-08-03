<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';

if ( ! in_array( $mode, array( 'absent', 'provider-seven-addon-fourteen', 'provider-seven-addon-fourteen-addon-first', 'provider-eight-addon-thirteen', 'provider-eight-addon-thirteen-addon-first', 'provider-seven-addon-thirteen', 'provider-seven-addon-thirteen-addon-first', 'compatible', 'compatible-core-first', 'compatible-addon-first', 'unsupported-multisite', 'inactive' ), true ) ) {
	fwrite( STDERR, "A valid lifecycle mode is required.\n" );
	exit( 2 );
}

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['ran_booster_bitbucket_fixture_actions'] = array();
$GLOBALS['ran_booster_bitbucket_fixture_filters'] = array();
$addOnLoaded                              = false;
$markersDefinedWhenAddOnLoaded           = null;
$compatibleModes                         = array( 'compatible', 'compatible-core-first', 'compatible-addon-first', 'unsupported-multisite' );
$incompatibleModes                       = array( 'provider-seven-addon-fourteen', 'provider-seven-addon-fourteen-addon-first', 'provider-eight-addon-thirteen', 'provider-eight-addon-thirteen-addon-first', 'provider-seven-addon-thirteen', 'provider-seven-addon-thirteen-addon-first' );
$addOnFirstModes                         = array( 'compatible-addon-first', 'provider-seven-addon-fourteen-addon-first', 'provider-eight-addon-thirteen-addon-first', 'provider-seven-addon-thirteen-addon-first' );
$coreBackedModes                         = array_merge( $compatibleModes, $incompatibleModes );
$loadAddOn                               = static function () use ( &$addOnLoaded, &$markersDefinedWhenAddOnLoaded ): void {
	$markersDefinedWhenAddOnLoaded = defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' )
		&& defined( 'RAN_BOOSTER_ADDON_API_VERSION' )
		&& defined( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' );
	require dirname( __DIR__, 2 ) . '/ran-booster-bitbucket.php';
	$addOnLoaded = true;
};

/** @param callable $callback */
function add_action( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): void {
	unset( $priority, $acceptedArgs );
	$GLOBALS['ran_booster_bitbucket_fixture_actions'][ $hook ][] = $callback;
}

/** @param callable $callback */
function add_filter( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): void {
	unset( $priority, $acceptedArgs );
	$GLOBALS['ran_booster_bitbucket_fixture_filters'][ $hook ][] = $callback;
}

function esc_html__( string $text ): string {
	return $text;
}

function __( string $text ): string {
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

if ( in_array( $mode, $coreBackedModes, true ) ) {
	$coreRoot = getenv( 'RAN_BOOSTER_CORE_PATH' );
	$coreRoot = false === $coreRoot || '' === $coreRoot
		? dirname( __DIR__, 3 ) . '/ran-booster'
		: rtrim( $coreRoot, '/\\' );
	$coreAutoload = $coreRoot . '/vendor/autoload.php';

	if ( ! is_file( $coreAutoload ) ) {
		fwrite( STDERR, "A compatible RAN Booster sibling checkout is required.\n" );
		exit( 3 );
	}

	if ( in_array( $mode, $addOnFirstModes, true ) ) {
		$loadAddOn();
	}

	require $coreAutoload;
	$apiVersions = match ( $mode ) {
		'provider-seven-addon-fourteen', 'provider-seven-addon-fourteen-addon-first' => array( 7, 14 ),
		'provider-eight-addon-thirteen', 'provider-eight-addon-thirteen-addon-first' => array( 8, 13 ),
		'provider-seven-addon-thirteen', 'provider-seven-addon-thirteen-addon-first' => array( 7, 13 ),
		default => array( 8, 14 ),
	};
	define( 'RAN_BOOSTER_PROVIDER_API_VERSION', $apiVersions[0] );
	define( 'RAN_BOOSTER_ADDON_API_VERSION', $apiVersions[1] );
	define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 2 );
}

if ( 'unsupported-multisite' === $mode ) {
	define( 'RAN_BOOSTER_RUNTIME_MODE', 'multisite_unsupported' );
}

if ( 'inactive' !== $mode && ! $addOnLoaded ) {
	$loadAddOn();
}

$callbacks = $GLOBALS['ran_booster_bitbucket_fixture_actions']['ran_booster_register_providers'] ?? array();
$documentationFilters = $GLOBALS['ran_booster_bitbucket_fixture_filters']['ran_booster_documentation_sections_after_provider_bb'] ?? array();
$adminInteractionCallbacks = $GLOBALS['ran_booster_bitbucket_fixture_actions']['ran_booster_admin_interaction_ready'] ?? array();
$documentationSections = array();
$documentation          = '';
if ( array() !== $documentationFilters ) {
	$documentationSections = $documentationFilters[0]( array(), 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=documentation', 'site' );
	if ( array() !== $documentationSections ) {
		ob_start();
		$documentationSections[0]['content']();
		$documentation = (string) ob_get_clean();
	}
}
$result    = array(
	'provider_callbacks'           => count( $callbacks ),
	'documentation_filters'        => count( $documentationFilters ),
	'documentation_sections'       => count( $documentationSections ),
	'documentation'                => $documentation,
	'admin_interaction_callbacks'  => count( $adminInteractionCallbacks ),
	'admin_interaction_api_version' => defined( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' )
		? RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION
		: null,
	'markers_defined_when_loaded'  => $markersDefinedWhenAddOnLoaded,
	'provider_loaded'              => class_exists( 'RAN\\Booster\\Bitbucket\\BitbucketProvider', false ),
	'registered'                   => false,
	'provider_code'                => '',
	'credential_store_was_scoped'  => false,
	'credential_store_reads'       => 0,
	'implements_release_catalog'   => false,
	'implements_webhook_fitness'   => false,
	'implements_webhook_management' => false,
	'remote_calls'                 => 0,
);

if ( in_array( $mode, $coreBackedModes, true ) ) {
	$store = new class() implements \RAN\RepositoryProvider\ProviderCredentialStore {
		public int $reads = 0;

		public function credentialProfiles(): array {
			++$this->reads;

			return array();
		}

		public function credentialMaterial( ?string $id = null ): ?array {
			++$this->reads;

			return null;
		}

		public function hasWebhookProfile(): bool {
			++$this->reads;

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
	$result['registered'] = array_key_exists( 'bb', $registry->all() );
	if ( $result['registered'] ) {
		$provider                             = $registry->get( 'bb' );
		$result['provider_code']              = $provider->getMetadata()->code->value;
		$result['implements_release_catalog'] = $provider instanceof \RAN\RepositoryProvider\ReleaseCatalog;
		$result['implements_webhook_fitness'] = $provider instanceof \RAN\RepositoryProvider\RepositoryWebhookFitness;
		$result['implements_webhook_management'] = $provider instanceof \RAN\RepositoryProvider\RepositoryWebhookManagement;
	}
	$result['credential_store_reads'] = $store->reads;
} elseif ( array() !== $callbacks ) {
	$callbacks[0]( new stdClass() );
}

echo json_encode( $result, JSON_THROW_ON_ERROR );
