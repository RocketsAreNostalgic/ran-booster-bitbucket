<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.

declare(strict_types=1);

require_once __DIR__ . '/fixtures/certified-core-checkout.php';

$core_root            = ran_booster_bitbucket_certified_core_root();
$core_vendor_autoload = getenv( 'RAN_BOOSTER_CORE_VENDOR_AUTOLOAD' );
$core_autoload        = $core_root . '/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- This fixture exercises the exact foreign WordPress/Core contract name.
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( false !== $core_vendor_autoload && '' !== $core_vendor_autoload ) {
	require $core_vendor_autoload;
}
require $core_autoload;
require dirname( __DIR__ ) . '/autoload.php';
