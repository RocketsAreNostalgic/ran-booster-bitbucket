<?php

// Executed by WP-CLI inside an explicitly marked disposable WordPress installation.
// phpcs:disable

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'The installed Bitbucket inertness proof is restricted to WP-CLI.' );
}

$mode = getenv( 'RAN_BOOSTER_BITBUCKET_INERT_MODE' );
if ( ! in_array( $mode, array( 'absent', 'incompatible' ), true ) ) {
	throw new RuntimeException( 'A supported installed inertness mode is required.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$pluginFile = WP_PLUGIN_DIR . '/ran-booster-bitbucket/ran-booster-bitbucket.php';
$pluginData = get_plugin_data( $pluginFile, false, false );
if ( '0.1.0-beta.6' !== ( $pluginData['Version'] ?? null )
	|| 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket' !== ( $pluginData['UpdateURI'] ?? null )
) {
	throw new RuntimeException( 'The inertness lane did not retain the exact installed Bitbucket candidate.' );
}

if ( 'incompatible' === $mode ) {
	define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 7 );
	define( 'RAN_BOOSTER_ADDON_API_VERSION', 13 );
	define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 2 );
}
require $pluginFile;

$callbackPriority = has_action( 'ran_booster_register_providers' );
if ( false === $callbackPriority ) {
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
if ( 'incompatible' === $mode
	&& ( ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' ) || 7 !== RAN_BOOSTER_PROVIDER_API_VERSION
		|| ! defined( 'RAN_BOOSTER_ADDON_API_VERSION' ) || 13 !== RAN_BOOSTER_ADDON_API_VERSION )
) {
	throw new RuntimeException( 'The incompatible installed Core fixture is invalid.' );
}

WP_CLI::success( 'Installed Bitbucket absent/incompatible Core inertness passed.' );
