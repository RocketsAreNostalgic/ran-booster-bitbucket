<?php

declare(strict_types=1);

$fixture_mode = $argv[1] ?? '';

if ( ! in_array( $fixture_mode, array( 'absent', 'absent-unprivileged', 'provider-twelve-addon-seventeen', 'provider-twelve-addon-seventeen-addon-first', 'provider-thirteen-addon-seventeen', 'provider-thirteen-addon-seventeen-addon-first', 'provider-fourteen-addon-sixteen', 'provider-fourteen-addon-sixteen-addon-first', 'provider-eleven-addon-fifteen', 'provider-eleven-addon-fifteen-addon-first', 'provider-fifteen-addon-seventeen', 'provider-fifteen-addon-seventeen-addon-first', 'provider-fourteen-addon-eighteen', 'provider-fourteen-addon-eighteen-addon-first', 'compatible', 'compatible-core-first', 'compatible-addon-first', 'unsupported-multisite', 'inactive' ), true ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to its standard stream without WordPress.
	fwrite( STDERR, "A valid lifecycle mode is required.\n" );
	exit( 2 );
}

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['ran_booster_bitbucket_fixture_actions'] = array();
$GLOBALS['ran_booster_bitbucket_fixture_filters'] = array();
// Observe every implementation autoload attempt, including unsuccessful requests.
$implementation_load_attempts = 0;
spl_autoload_register(
	static function ( string $class_name ) use ( &$implementation_load_attempts ): void {
		if ( str_starts_with( $class_name, 'RAN\\Booster\\Bitbucket\\' ) && 'RAN\\Booster\\Bitbucket\\Plugin' !== $class_name ) {
			++$implementation_load_attempts;
		}
	},
	true,
	true
);
$add_on_loaded                      = false;
$markers_defined_when_add_on_loaded = null;
$compatible_modes                   = array( 'compatible', 'compatible-core-first', 'compatible-addon-first', 'unsupported-multisite' );
$incompatible_modes                 = array( 'provider-fifteen-addon-seventeen', 'provider-fifteen-addon-seventeen-addon-first', 'provider-fourteen-addon-eighteen', 'provider-fourteen-addon-eighteen-addon-first', 'provider-twelve-addon-seventeen', 'provider-twelve-addon-seventeen-addon-first', 'provider-thirteen-addon-seventeen', 'provider-thirteen-addon-seventeen-addon-first', 'provider-fourteen-addon-sixteen', 'provider-fourteen-addon-sixteen-addon-first', 'provider-eleven-addon-fifteen', 'provider-eleven-addon-fifteen-addon-first' );
$add_on_first_modes                 = array( 'provider-fifteen-addon-seventeen-addon-first', 'provider-fourteen-addon-eighteen-addon-first', 'compatible-addon-first', 'provider-twelve-addon-seventeen-addon-first', 'provider-thirteen-addon-seventeen-addon-first', 'provider-fourteen-addon-sixteen-addon-first', 'provider-eleven-addon-fifteen-addon-first' );
$core_backed_modes                  = array_merge( $compatible_modes, $incompatible_modes );
$load_add_on                        = static function () use ( &$add_on_loaded, &$markers_defined_when_add_on_loaded ): void {
	$markers_defined_when_add_on_loaded = defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' )
		&& defined( 'RAN_BOOSTER_ADDON_API_VERSION' )
		&& defined( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' );
	require dirname( __DIR__, 2 ) . '/ran-booster-bitbucket.php';
	$add_on_loaded = true;
};

/** @param callable $callback */
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $priority, $accepted_args );
	$GLOBALS['ran_booster_bitbucket_fixture_actions'][ $hook ][] = $callback;
}

/** @param callable $callback */
function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $priority, $accepted_args );
	$GLOBALS['ran_booster_bitbucket_fixture_filters'][ $hook ][] = $callback;
}

function esc_html__( string $text ): string {
	return $text;
}

function __( string $text ): string {
	return $text;
}

function esc_html_e( string $text ): void {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixture implements the foreign escaping function itself; return its controlled stand-in output.
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone fixture produces machine-readable JSON without WordPress.
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

$GLOBALS['ran_booster_bitbucket_fixture_mode']         = $fixture_mode;
$GLOBALS['ran_booster_bitbucket_fixture_remote_calls'] = 0;

if ( in_array( $fixture_mode, $core_backed_modes, true ) ) {
	$core_root     = getenv( 'RAN_BOOSTER_CORE_PATH' );
	$core_root     = false === $core_root || '' === $core_root
		? dirname( __DIR__, 3 ) . '/ran-booster'
		: rtrim( $core_root, '/\\' );
	$core_autoload = $core_root . '/autoload.php';

	if ( ! is_file( $core_autoload ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to its standard stream without WordPress.
		fwrite( STDERR, "The exact certified RAN Booster production source is required.\n" );
		exit( 3 );
	}

	if ( in_array( $fixture_mode, $add_on_first_modes, true ) ) {
		$load_add_on();
	}

	$core_vendor_autoload = getenv( 'RAN_BOOSTER_CORE_VENDOR_AUTOLOAD' );
	if ( false !== $core_vendor_autoload && '' !== $core_vendor_autoload ) {
		require $core_vendor_autoload;
	}
	require $core_autoload;
	$api_versions = match ( $fixture_mode ) {
		'provider-fifteen-addon-seventeen', 'provider-fifteen-addon-seventeen-addon-first' => array( 15, 17 ),
		'provider-fourteen-addon-eighteen', 'provider-fourteen-addon-eighteen-addon-first' => array( 14, 18 ),
		'provider-thirteen-addon-seventeen', 'provider-thirteen-addon-seventeen-addon-first' => array( 13, 17 ),
		'provider-fourteen-addon-sixteen', 'provider-fourteen-addon-sixteen-addon-first' => array( 14, 16 ),
		'provider-eleven-addon-fifteen', 'provider-eleven-addon-fifteen-addon-first' => array( 11, 15 ),
		'provider-twelve-addon-seventeen', 'provider-twelve-addon-seventeen-addon-first' => array( 12, 17 ),
		default => array( 14, 17 ),
	};
	define( 'RAN_BOOSTER_PROVIDER_API_VERSION', $api_versions[0] );
	define( 'RAN_BOOSTER_ADDON_API_VERSION', $api_versions[1] );
	define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 3 );
}

if ( 'unsupported-multisite' === $fixture_mode ) {
	define( 'RAN_BOOSTER_RUNTIME_MODE', 'multisite_unsupported' );
}

if ( 'inactive' !== $fixture_mode && ! $add_on_loaded ) {
	$load_add_on();
}

$callbacks                   = $GLOBALS['ran_booster_bitbucket_fixture_actions']['ran_booster_register_providers'] ?? array();
$documentation_filters       = $GLOBALS['ran_booster_bitbucket_fixture_filters']['ran_booster_documentation_sections_after_provider_bb'] ?? array();
$admin_interaction_callbacks = $GLOBALS['ran_booster_bitbucket_fixture_actions']['ran_booster_admin_interaction_ready'] ?? array();
$notice_callbacks            = $GLOBALS['ran_booster_bitbucket_fixture_actions']['admin_notices'] ?? array();
$documentation_sections      = array();
$documentation               = '';
$notice                      = '';
if ( array() !== $documentation_filters ) {
	$documentation_sections = $documentation_filters[0]( array(), 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=documentation', 'site' );
	if ( array() !== $documentation_sections ) {
		ob_start();
		$documentation_sections[0]['content']();
		$documentation = (string) ob_get_clean();
	}
}

if ( array() !== $notice_callbacks ) {
	ob_start();
	$notice_callbacks[0]();
	$notice = (string) ob_get_clean();
}
$result = array(
	'provider_callbacks'              => count( $callbacks ),
	'documentation_filters'           => count( $documentation_filters ),
	'documentation_sections'          => count( $documentation_sections ),
	'documentation'                   => $documentation,
	'compatibility_notice'            => $notice,
	'admin_interaction_callbacks'     => count( $admin_interaction_callbacks ),
	'admin_interaction_api_version'   => defined( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' )
		? RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION
		: null,
	'markers_defined_when_loaded'     => $markers_defined_when_add_on_loaded,
	'provider_loaded'                 => class_exists( 'RAN\\Booster\\Bitbucket\\BitbucketProvider', false ),
	'registered'                      => false,
	'provider_code'                   => '',
	'credential_store_was_scoped'     => false,
	'delivery_evidence_was_scoped'    => false,
	'owner_requires_managed_target'   => false,
	'navigation_slot'                 => 0,
	'credential_store_reads'          => 0,
	'implements_release_capabilities' => array(),
	'implements_webhook_fitness'      => false,
	'implements_webhook_management'   => false,
	'remote_calls'                    => 0,
	'operation_locator'               => '',
);

if ( in_array( $fixture_mode, $core_backed_modes, true ) ) {
	$store    = new class() implements \RAN\RepositoryProvider\ProviderCredentialStore {
		public int $reads = 0;

		public function credential_profiles(): array {
			++$this->reads;

			return array();
		}

		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Preserve the ProviderCredentialStore foreign credential_material parameter signature in this inert test double.
		public function credential_material( ?string $id = null ): ?array {
			++$this->reads;

			return null;
		}

		public function has_webhook_profile(): bool {
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
				public function latest_authenticated_delivery(): ?\RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence {
					return null;
				}
			};
		},
		new \RAN\RepositoryProvider\ProviderRegistrationContext( static fn (): int => 52_428_800 )
	);

	$callbacks[0]( $registry );
	$result['registered'] = array_key_exists( 'bb', $registry->all() );
	if ( $result['registered'] ) {
		$provider                                  = $registry->get( 'bb' );
		$metadata                                  = $provider->get_metadata();
		$result['provider_code']                   = $metadata->code->value;
		$result['owner_requires_managed_target']   = $metadata->admin?->get_webhook_scope( 'owner' )?->requires_managed_target ?? false;
		$result['navigation_slot']                 = $metadata->admin?->navigation?->slot ?? 0;
		$result['implements_release_capabilities'] = array(
			$provider instanceof \RAN\RepositoryProvider\RepositoryReleaseMetadata,
			$provider instanceof \RAN\RepositoryProvider\RepositoryReleaseCandidateListing,
			$provider instanceof \RAN\RepositoryProvider\RepositoryReleaseInspector,
			$provider instanceof \RAN\RepositoryProvider\RepositoryReleaseAcquirer,
			$provider instanceof \RAN\RepositoryProvider\RepositoryReleaseNativeTargets,
		);
		$result['implements_webhook_fitness']      = $provider instanceof \RAN\RepositoryProvider\RepositoryWebhookFitness;
		$result['implements_webhook_management']   = $provider instanceof \RAN\RepositoryProvider\RepositoryWebhookManagement;
		$repository                                = $provider->resolve_repository(
			new \RAN\RepositoryProvider\RepositoryLookupRequest( 'example/reference-plugin', null, true )
		);
		$result['operation_locator']               = $repository->locator;
	}
	$result['credential_store_reads'] = $store->reads;
} elseif ( array() !== $callbacks ) {
	$callbacks[0]( new stdClass() );
}

$result['provider_loaded']              = class_exists( 'RAN\\Booster\\Bitbucket\\BitbucketProvider', false );
$result['implementation_load_attempts'] = $implementation_load_attempts;
$result['remote_calls']                 = $GLOBALS['ran_booster_bitbucket_fixture_remote_calls'];

// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone fixture produces machine-readable JSON without WordPress.
echo json_encode( $result, JSON_THROW_ON_ERROR );
