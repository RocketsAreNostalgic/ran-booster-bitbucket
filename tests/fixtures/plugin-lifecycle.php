<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';

if ( ! in_array( $mode, array( 'absent', 'absent-unprivileged', 'provider-eight-addon-fifteen', 'provider-eight-addon-fifteen-addon-first', 'provider-nine-addon-fourteen', 'provider-nine-addon-fourteen-addon-first', 'provider-eight-addon-fourteen', 'provider-eight-addon-fourteen-addon-first', 'compatible', 'compatible-core-first', 'compatible-addon-first', 'unsupported-multisite', 'inactive' ), true ) ) {
	fwrite( STDERR, "A valid lifecycle mode is required.\n" );
	exit( 2 );
}

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['ran_booster_bitbucket_fixture_actions'] = array();
$GLOBALS['ran_booster_bitbucket_fixture_filters'] = array();
$addOnLoaded                              = false;
$markersDefinedWhenAddOnLoaded           = null;
$compatibleModes                         = array( 'compatible', 'compatible-core-first', 'compatible-addon-first', 'unsupported-multisite' );
$incompatibleModes                       = array( 'provider-eight-addon-fifteen', 'provider-eight-addon-fifteen-addon-first', 'provider-nine-addon-fourteen', 'provider-nine-addon-fourteen-addon-first', 'provider-eight-addon-fourteen', 'provider-eight-addon-fourteen-addon-first' );
$addOnFirstModes                         = array( 'compatible-addon-first', 'provider-eight-addon-fifteen-addon-first', 'provider-nine-addon-fourteen-addon-first', 'provider-eight-addon-fourteen-addon-first' );
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

function current_user_can( string $capability ): bool {
	return 'activate_plugins' === $capability && 'absent-unprivileged' !== ( $GLOBALS['ran_booster_bitbucket_fixture_mode'] ?? '' );
}

function wp_parse_url( string $url ): array|false {
	return parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress fixture stand-in.
}

function wp_remote_get( string $url, array $arguments ): array {
	unset( $url, $arguments );
	++$GLOBALS['ran_booster_bitbucket_fixture_remote_calls'];

	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode(
			array(
				'uuid'       => '{controlled-repository}',
				'full_name'  => 'example/reference-plugin',
				'is_private' => false,
				'mainbranch' => array( 'name' => 'main' ),
			),
			JSON_THROW_ON_ERROR
		),
	);
}

function is_wp_error( mixed $value ): bool {
	unset( $value );

	return false;
}

function wp_remote_retrieve_response_code( array $response ): int {
	return (int) $response['response']['code'];
}

function wp_remote_retrieve_body( array $response ): string {
	return (string) $response['body'];
}

$GLOBALS['ran_booster_bitbucket_fixture_mode']         = $mode;
$GLOBALS['ran_booster_bitbucket_fixture_remote_calls'] = 0;

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
		'provider-eight-addon-fifteen', 'provider-eight-addon-fifteen-addon-first' => array( 8, 15 ),
		'provider-nine-addon-fourteen', 'provider-nine-addon-fourteen-addon-first' => array( 9, 14 ),
		'provider-eight-addon-fourteen', 'provider-eight-addon-fourteen-addon-first' => array( 8, 14 ),
		default => array( 9, 15 ),
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
$noticeCallbacks = $GLOBALS['ran_booster_bitbucket_fixture_actions']['admin_notices'] ?? array();
$documentationSections = array();
$documentation          = '';
$notice                 = '';
if ( array() !== $documentationFilters ) {
	$documentationSections = $documentationFilters[0]( array(), 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=documentation', 'site' );
	if ( array() !== $documentationSections ) {
		ob_start();
		$documentationSections[0]['content']();
		$documentation = (string) ob_get_clean();
	}
}

if ( array() !== $noticeCallbacks ) {
	ob_start();
	$noticeCallbacks[0]();
	$notice = (string) ob_get_clean();
}
$result    = array(
	'provider_callbacks'           => count( $callbacks ),
	'documentation_filters'        => count( $documentationFilters ),
	'documentation_sections'       => count( $documentationSections ),
	'documentation'                => $documentation,
	'compatibility_notice'         => $notice,
	'admin_interaction_callbacks'  => count( $adminInteractionCallbacks ),
	'admin_interaction_api_version' => defined( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' )
		? RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION
		: null,
	'markers_defined_when_loaded'  => $markersDefinedWhenAddOnLoaded,
	'provider_loaded'              => class_exists( 'RAN\\Booster\\Bitbucket\\BitbucketProvider', false ),
	'registered'                   => false,
	'provider_code'                => '',
	'credential_store_was_scoped'  => false,
	'delivery_evidence_was_scoped' => false,
	'owner_requires_managed_target' => false,
	'navigation_slot'              => 0,
	'credential_store_reads'       => 0,
	'implements_release_catalog'   => false,
	'implements_webhook_fitness'   => false,
	'implements_webhook_management' => false,
	'remote_calls'                 => 0,
	'operation_locator'            => '',
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
		},
		static function ( \RAN\RepositoryProvider\ProviderCode $code ) use ( &$result ): \RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader {
			$result['delivery_evidence_was_scoped'] = 'bb' === $code->value;

			return new class() implements \RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader {
				public function latestAuthenticatedDelivery(): ?\RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence {
					return null;
				}
			};
		}
	);

	$callbacks[0]( $registry );
	$result['registered'] = array_key_exists( 'bb', $registry->all() );
	if ( $result['registered'] ) {
		$provider                             = $registry->get( 'bb' );
		$metadata                             = $provider->getMetadata();
		$result['provider_code']              = $metadata->code->value;
		$result['owner_requires_managed_target'] = $metadata->admin?->getWebhookScope( 'owner' )?->requiresManagedTarget ?? false;
		$result['navigation_slot']            = $metadata->admin?->navigation?->slot ?? 0;
		$result['implements_release_catalog'] = $provider instanceof \RAN\RepositoryProvider\ReleaseCatalog;
		$result['implements_webhook_fitness'] = $provider instanceof \RAN\RepositoryProvider\RepositoryWebhookFitness;
		$result['implements_webhook_management'] = $provider instanceof \RAN\RepositoryProvider\RepositoryWebhookManagement;
		$repository = $provider->resolveRepository(
			new \RAN\RepositoryProvider\RepositoryLookupRequest( 'example/reference-plugin', null, true )
		);
		$result['operation_locator'] = $repository->locator;
	}
	$result['credential_store_reads'] = $store->reads;
} elseif ( array() !== $callbacks ) {
	$callbacks[0]( new stdClass() );
}

$result['remote_calls'] = $GLOBALS['ran_booster_bitbucket_fixture_remote_calls'];

echo json_encode( $result, JSON_THROW_ON_ERROR );
