<?php

// Executed by WP-CLI inside an explicitly marked disposable WordPress installation.
// phpcs:disable

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'The installed Bitbucket proof is restricted to WP-CLI.' );
}

$expectedOrder = getenv( 'RAN_BOOSTER_BITBUCKET_LOAD_ORDER' );
if ( ! in_array( $expectedOrder, array( 'addon-first', 'core-first' ), true ) ) {
	throw new RuntimeException( 'A supported installed load order is required.' );
}

$coreSource = getenv( 'RAN_BOOSTER_CORE_SOURCE_PATH' );
if ( false === $coreSource || '' === $coreSource ) {
	throw new RuntimeException( 'The exact certified Core source path is required.' );
}
$expectedVersion = getenv( 'RAN_BOOSTER_BITBUCKET_VERSION' );
if ( false === $expectedVersion || '' === $expectedVersion ) {
	throw new RuntimeException( 'The expected installed Bitbucket version is required.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$pluginFile = WP_PLUGIN_DIR . '/ran-booster-bitbucket/ran-booster-bitbucket.php';
$pluginData = get_plugin_data( $pluginFile, false, false );
$expected   = array(
	'Name'            => 'RAN Booster Bitbucket Cloud',
	'Version'         => $expectedVersion,
	'UpdateURI'       => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket',
	'RequiresPlugins' => 'ran-booster',
	'RequiresWP'      => '7.0',
	'RequiresPHP'     => '8.2',
);
foreach ( $expected as $key => $value ) {
	if ( ( $pluginData[ $key ] ?? null ) !== $value ) {
		throw new RuntimeException( 'Unexpected installed Bitbucket header: ' . $key );
	}
}

$active = array_values( get_option( 'active_plugins', array() ) );
$addon  = array_search( 'ran-booster-bitbucket/ran-booster-bitbucket.php', $active, true );
$core   = array_search( 'ran-booster/ran-booster.php', $active, true );
if ( false === $addon || false === $core
	|| ( 'addon-first' === $expectedOrder && $addon >= $core )
	|| ( 'core-first' === $expectedOrder && $core >= $addon )
) {
	throw new RuntimeException( 'The installed plugins did not load in the requested order.' );
}

if ( ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' ) || 10 !== RAN_BOOSTER_PROVIDER_API_VERSION
	|| ! defined( 'RAN_BOOSTER_ADDON_API_VERSION' ) || 15 !== RAN_BOOSTER_ADDON_API_VERSION
) {
	throw new RuntimeException( 'The exact certified Core API generation is unavailable.' );
}

$container = require rtrim( $coreSource, '/\\' ) . '/tests/WordPress/core-container-fixture.php';
$registry  = $container->make( RAN\RepositoryProvider\ProviderRegistry::class );
if ( ! $registry->isSealed() ) {
	throw new RuntimeException( 'The installed provider registry is not sealed.' );
}

$providers = array_keys( $registry->all() );
if ( 1 !== count( array_filter( $providers, static fn( string $code ): bool => 'bb' === $code ) ) ) {
	throw new RuntimeException( 'The installed Bitbucket provider did not register exactly once.' );
}
$provider = $registry->get( 'bb' );
if ( 'bb' !== $provider->getMetadata()->code->value
	|| $provider instanceof RAN\RepositoryProvider\ReleaseCatalog
	|| $provider instanceof RAN\RepositoryProvider\RepositoryWebhookFitness
	|| $provider instanceof RAN\RepositoryProvider\RepositoryWebhookManagement
) {
	throw new RuntimeException( 'The installed Bitbucket provider capability contract is invalid.' );
}

$allowedHooks = array(
	'ran_booster_register_providers',
	'ran_booster_documentation_sections_after_provider_bb',
	'admin_notices',
);
$ownedHooks   = array();
foreach ( $GLOBALS['wp_filter'] as $hookName => $hook ) {
	foreach ( is_object( $hook ) && is_array( $hook->callbacks ?? null ) ? $hook->callbacks : array() as $callbacks ) {
		foreach ( is_array( $callbacks ) ? $callbacks : array() as $registered ) {
			$callback = is_array( $registered ) ? ( $registered['function'] ?? null ) : null;
			if ( is_array( $callback ) && ( $callback[0] ?? null ) instanceof RAN\Booster\Bitbucket\Plugin ) {
				$ownedHooks[] = (string) $hookName;
			}
		}
	}
}
sort( $ownedHooks );
$expectedHooks = $allowedHooks;
sort( $expectedHooks );
if ( $ownedHooks !== $expectedHooks ) {
	throw new RuntimeException( 'The installed Bitbucket add-on owns an unexpected WordPress hook.' );
}

$requests = 0;
$transport = static function ( mixed $response, array $arguments, string $url ) use ( &$requests ): array {
	unset( $response );
	$expectedUrl = 'https://api.bitbucket.org/2.0/repositories/rocketsarenostalgic/ran-booster-fixture-public-plugin?fields=uuid%2Cfull_name%2Cis_private%2Cmainbranch.name';
	if ( $url !== $expectedUrl
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
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $transport, 10, 3 );
try {
	$repository = $provider->resolveRepository(
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
	|| '{5a314489-3bbb-4914-9568-01ee989751e1}' !== $repository->providerRepositoryId
	|| $repository->private
	|| 'main' !== $repository->defaultBranch
) {
	throw new RuntimeException( 'The controlled installed provider operation failed.' );
}

$sections = apply_filters(
	'ran_booster_documentation_sections_after_provider_bb',
	array(),
	admin_url( 'admin.php?page=ran-booster&tab=documentation' ),
	'site'
);
if ( 1 !== count( $sections ) || 'ran-booster-documentation-bitbucket-cloud' !== ( $sections[0]['id'] ?? null ) ) {
	throw new RuntimeException( 'The installed Bitbucket documentation contribution is unavailable.' );
}

WP_CLI::success( 'Installed Bitbucket identity, load order, provider contract and controlled operation passed.' );
