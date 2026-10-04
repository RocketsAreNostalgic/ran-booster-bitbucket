<?php

// Executed by WP-CLI inside an explicitly marked disposable WordPress installation.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'The installed Bitbucket inertness proof is restricted to WP-CLI.' );
}

$fixture_mode = getenv( 'RAN_BOOSTER_BITBUCKET_INERT_MODE' );
if ( ! in_array( $fixture_mode, array( 'absent', 'incompatible', 'provider-twelve', 'provider-fifteen', 'addon-sixteen', 'addon-eighteen' ), true ) ) {
	throw new RuntimeException( 'A supported installed inertness mode is required.' );
}
$expected_version = getenv( 'RAN_BOOSTER_BITBUCKET_VERSION' );
if ( false === $expected_version || '' === $expected_version ) {
	throw new RuntimeException( 'The expected installed Bitbucket version is required.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin_file = WP_PLUGIN_DIR . '/ran-booster-bitbucket/ran-booster-bitbucket.php';
$plugin_data = get_plugin_data( $plugin_file, false, false );
if ( ( $plugin_data['Version'] ?? null ) !== $expected_version
	|| 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket' !== ( $plugin_data['UpdateURI'] ?? null )
) {
	throw new RuntimeException( 'The inertness lane did not retain the exact installed Bitbucket candidate.' );
}

$api_versions = match ( $fixture_mode ) {
	'incompatible' => array( 13, 17 ),
	'provider-twelve' => array( 12, 17 ),
	'provider-fifteen' => array( 15, 17 ),
	'addon-sixteen' => array( 14, 16 ),
	'addon-eighteen' => array( 14, 18 ),
	default => null,
};
if ( null !== $api_versions ) {
	define( 'RAN_BOOSTER_PROVIDER_API_VERSION', $api_versions[0] );
	define( 'RAN_BOOSTER_ADDON_API_VERSION', $api_versions[1] );
	define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 3 );
}
require $plugin_file;

$callback_priority = has_action( 'ran_booster_register_providers' );
if ( false === $callback_priority ) {
	throw new RuntimeException( 'The installed Bitbucket dependency/load contract is invalid.' );
}

$requests  = 0;
$transport = static function ( mixed $response ) use ( &$requests ): mixed {
	++$requests;

	return $response;
};
add_filter( 'pre_http_request', $transport );
try {
	do_action( 'ran_booster_register_providers', new stdClass() );
	$sections = apply_filters(
		'ran_booster_documentation_sections_after_provider_bb',
		array(),
		admin_url( 'admin.php?page=ran-booster&tab=documentation' ),
		'site'
	);
} finally {
	remove_filter( 'pre_http_request', $transport );
}

if ( 0 !== $requests || array() !== $sections || class_exists( 'RAN\\Booster\\Bitbucket\\BitbucketProvider', false ) ) {
	throw new RuntimeException( 'The installed Bitbucket candidate was not inert without exact Core.' );
}
if ( null !== $api_versions
	&& ( ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' ) || RAN_BOOSTER_PROVIDER_API_VERSION !== $api_versions[0]
		|| ! defined( 'RAN_BOOSTER_ADDON_API_VERSION' ) || RAN_BOOSTER_ADDON_API_VERSION !== $api_versions[1] )
) {
	throw new RuntimeException( 'The incompatible installed Core fixture is invalid.' );
}

WP_CLI::success( 'Installed Bitbucket absent/incompatible Core inertness passed.' );
