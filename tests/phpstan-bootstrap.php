<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/certified-core-checkout.php';

$coreRoot           = ran_booster_bitbucket_certified_core_root();
$coreVendorAutoload = getenv( 'RAN_BOOSTER_CORE_VENDOR_AUTOLOAD' );
$coreAutoload       = $coreRoot . '/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( false !== $coreVendorAutoload && '' !== $coreVendorAutoload ) {
	require $coreVendorAutoload;
}
require $coreAutoload;
require dirname( __DIR__ ) . '/autoload.php';
