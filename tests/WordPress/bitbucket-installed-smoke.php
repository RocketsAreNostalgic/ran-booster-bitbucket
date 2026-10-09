<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.

// Executed by WP-CLI inside an explicitly marked disposable WordPress installation.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'The installed Bitbucket proof is restricted to WP-CLI.' );
}

$expected_order = getenv( 'RAN_BOOSTER_BITBUCKET_LOAD_ORDER' );
if ( ! in_array( $expected_order, array( 'addon-first', 'core-first' ), true ) ) {
	throw new RuntimeException( 'A supported installed load order is required.' );
}

$expected_version = getenv( 'RAN_BOOSTER_BITBUCKET_VERSION' );
if ( false === $expected_version || '' === $expected_version ) {
	throw new RuntimeException( 'The expected installed Bitbucket version is required.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin_file = WP_PLUGIN_DIR . '/ran-booster-bitbucket/ran-booster-bitbucket.php';
/** @var array<string, string> $plugin_data Installed headers are verified at the external WordPress boundary. */
$plugin_data = get_plugin_data( $plugin_file, false, false );
$expected    = array(
	'Name'            => 'RAN Booster Bitbucket Cloud',
	'Version'         => $expected_version,
	'UpdateURI'       => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket',
	'RequiresPlugins' => 'ran-booster',
	'RequiresWP'      => '7.0',
	'RequiresPHP'     => '8.2',
);
foreach ( $expected as $key => $value ) {
	if ( ( $plugin_data[ $key ] ?? null ) !== $value ) {
		throw new RuntimeException( 'Unexpected installed Bitbucket header: ' . $key );
	}
}

$active = array_values( get_option( 'active_plugins', array() ) );
$addon  = array_search( 'ran-booster-bitbucket/ran-booster-bitbucket.php', $active, true );
$core   = array_search( 'ran-booster/ran-booster.php', $active, true );
if ( false === $addon || false === $core
	|| ( 'addon-first' === $expected_order && $addon >= $core )
	|| ( 'core-first' === $expected_order && $core >= $addon )
) {
	throw new RuntimeException( 'The installed plugins did not load in the requested order.' );
}

if ( ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' ) || 14 !== RAN_BOOSTER_PROVIDER_API_VERSION
	|| ! defined( 'RAN_BOOSTER_ADDON_API_VERSION' ) || 17 !== RAN_BOOSTER_ADDON_API_VERSION
	|| ! interface_exists( RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader::class )
	|| ! class_exists( RAN\RepositoryProvider\ProviderRegistrationContext::class )
) {
	throw new RuntimeException( 'The required Core provider contract is unavailable.' );
}

$registration_hook      = $GLOBALS['wp_filter']['ran_booster_register_providers'] ?? null;
$registration_callbacks = array();
foreach ( is_object( $registration_hook ) && is_array( $registration_hook->callbacks ?? null ) ? $registration_hook->callbacks : array() as $callbacks ) {
	foreach ( is_array( $callbacks ) ? $callbacks : array() as $registered ) {
		$callback = is_array( $registered ) ? ( $registered['function'] ?? null ) : null;
		if ( is_array( $callback )
			&& ( $callback[0] ?? null ) instanceof RAN\Booster\Bitbucket\Plugin
			&& 'register_provider' === ( $callback[1] ?? null )
		) {
			$registration_callbacks[] = $callback;
		}
	}
}
if ( 1 !== count( $registration_callbacks ) ) {
	throw new RuntimeException( 'The installed Bitbucket provider callback did not register exactly once.' );
}

$credential_store_scoped  = false;
$delivery_evidence_scoped = false;
$store                    = new class() implements RAN\RepositoryProvider\ProviderCredentialStore {
	public function credential_profiles(): array {
		return array();
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Preserve the ProviderCredentialStore foreign credential_material parameter signature in this inert test double.
	public function credential_material( ?string $id = null ): ?array {
		return null;
	}

	public function has_webhook_profile(): bool {
		return true;
	}
};
$registry                 = new RAN\RepositoryProvider\ProviderRegistry(
	array(),
	new RAN\RepositoryProvider\ProviderSecretPolicyCatalog(),
	static function ( RAN\RepositoryProvider\ProviderCode $code ) use ( $store, &$credential_store_scoped ): RAN\RepositoryProvider\ProviderCredentialStore {
		$credential_store_scoped = 'bb' === $code->value;

		return $store;
	},
	static function ( RAN\RepositoryProvider\ProviderCode $code ) use ( &$delivery_evidence_scoped ): RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader {
		$delivery_evidence_scoped = 'bb' === $code->value;

		return new class() implements RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader {
			/** @return RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence */
			public function latest_authenticated_delivery(): RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence {
				return new RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence(
					RAN\RepositoryProvider\ProviderCode::parse( 'bb' ),
					gmdate( 'Y-m-d H:i:s' ),
					true
				);
			}
		};
	},
	new RAN\RepositoryProvider\ProviderRegistrationContext( static fn (): int => 52_428_800 )
);
if ( ! is_callable( $registration_callbacks[0] ) ) {
	throw new RuntimeException( 'The installed provider registration must be callable.' );
}
$registration_callbacks[0]( $registry );
$registry->seal();
if ( ! $credential_store_scoped || ! $delivery_evidence_scoped || ! $registry->is_sealed() ) {
	throw new RuntimeException( 'The installed provider registration did not receive provider-bound Core readers.' );
}

$providers = array_keys( $registry->all() );
if ( 1 !== count( array_filter( $providers, static fn( string $code ): bool => 'bb' === $code ) ) ) {
	throw new RuntimeException( 'The installed Bitbucket provider did not register exactly once.' );
}
$provider = $registry->get( 'bb' );
if ( 'bb' !== $provider->get_metadata()->code->value
	|| $provider instanceof RAN\RepositoryProvider\RepositoryReleaseMetadata
	|| $provider instanceof RAN\RepositoryProvider\RepositoryReleaseCandidateListing
	|| $provider instanceof RAN\RepositoryProvider\RepositoryReleaseInspector
	|| $provider instanceof RAN\RepositoryProvider\RepositoryReleaseAcquirer
	|| $provider instanceof RAN\RepositoryProvider\RepositoryReleaseNativeTargets
	|| $provider instanceof RAN\RepositoryProvider\RepositoryWebhookFitness
	|| $provider instanceof RAN\RepositoryProvider\RepositoryWebhookManagement
) {
	throw new RuntimeException( 'The installed Bitbucket provider capability contract is invalid.' );
}

$webhooks   = $registry->require_capability( 'bb', RAN\RepositoryProvider\WebhookNormalizer::class );
$diagnostic = $webhooks->diagnose_webhook_readiness();
if ( RAN\RepositoryProvider\ProviderDiagnosticResult::PASSED !== $diagnostic->status
	|| 'bb.webhook.delivery_verified' !== $diagnostic->code
) {
	throw new RuntimeException( 'The installed Bitbucket authenticated-delivery diagnostic is unavailable.' );
}

$allowed_hooks = array(
	'ran_booster_register_providers',
	'ran_booster_documentation_sections_after_provider_bb',
	'admin_notices',
);
$owned_hooks   = array();
foreach ( $GLOBALS['wp_filter'] as $hook_name => $hook ) {
	foreach ( is_object( $hook ) && is_array( $hook->callbacks ?? null ) ? $hook->callbacks : array() as $callbacks ) {
		foreach ( is_array( $callbacks ) ? $callbacks : array() as $registered ) {
			$callback = is_array( $registered ) ? ( $registered['function'] ?? null ) : null;
			if ( is_array( $callback ) && ( $callback[0] ?? null ) instanceof RAN\Booster\Bitbucket\Plugin ) {
				$owned_hooks[] = (string) $hook_name;
			}
		}
	}
}
sort( $owned_hooks );
$expected_hooks = $allowed_hooks;
sort( $expected_hooks );
if ( $owned_hooks !== $expected_hooks ) {
	throw new RuntimeException( 'The installed Bitbucket add-on owns an unexpected WordPress hook.' );
}

$requests  = 0;
$transport = static function ( mixed $response, array $arguments, string $url ) use ( &$requests ): array {
	unset( $response );
	$expected_url = 'https://api.bitbucket.org/2.0/repositories/rocketsarenostalgic/ran-booster-fixture-public-plugin?fields=uuid%2Cfull_name%2Cis_private%2Cmainbranch.name';
	if ( $url !== $expected_url
		|| 0 !== ( $arguments['redirection'] ?? null )
		|| 262144 !== ( $arguments['limit_response_size'] ?? null )
		|| true !== ( $arguments['reject_unsafe_urls'] ?? null )
		|| 'application/json' !== ( $arguments['headers']['Accept'] ?? null )
		|| 'RAN-Booster' !== ( $arguments['headers']['User-Agent'] ?? null )
		|| isset( $arguments['headers']['Authorization'] )
	) {
		throw new RuntimeException( 'The controlled operation requested an unexpected URL.' );
	}
	++$requests;

	return array(
		'headers'  => array(),
		'body'     => wp_json_encode(
			array(
				'uuid'       => '{5a314489-3bbb-4914-9568-01ee989751e1}',
				'full_name'  => 'rocketsarenostalgic/ran-booster-fixture-public-plugin',
				'is_private' => false,
				'mainbranch' => array( 'name' => 'main' ),
			)
		),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $transport, 10, 3 );
try {
	$repository = $provider->resolve_repository(
		new RAN\RepositoryProvider\RepositoryLookupRequest(
			'rocketsarenostalgic/ran-booster-fixture-public-plugin',
			null,
			true
		)
	);
} finally {
	remove_filter( 'pre_http_request', $transport, 10 );
}

if ( 1 !== $requests
	|| 'rocketsarenostalgic/ran-booster-fixture-public-plugin' !== $repository->locator
	|| '{5a314489-3bbb-4914-9568-01ee989751e1}' !== $repository->provider_repository_id
	|| $repository->private
	|| 'main' !== $repository->default_branch
) {
	throw new RuntimeException( 'The controlled installed provider operation failed.' );
}

$sections = apply_filters(
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This fixture exercises the exact foreign WordPress/Core contract name.
	'ran_booster_documentation_sections_after_provider_bb',
	array(),
	admin_url( 'admin.php?page=ran-booster&tab=documentation' ),
	'site'
);
if ( 1 !== count( $sections ) || 'ran-booster-documentation-bitbucket-cloud' !== ( $sections[0]['id'] ?? null ) ) {
	throw new RuntimeException( 'The installed Bitbucket documentation contribution is unavailable.' );
}

WP_CLI::success( 'Installed Bitbucket identity, load order, provider contract, authenticated-delivery diagnostic and controlled operation passed.' );
