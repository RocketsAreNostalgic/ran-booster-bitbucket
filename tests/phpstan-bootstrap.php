<?php

declare(strict_types=1);

$coreRoot = getenv( 'RAN_BOOSTER_CORE_PATH' );
$coreRoot = false === $coreRoot || '' === $coreRoot
	? dirname( __DIR__ ) . '/../ran-booster'
	: rtrim( $coreRoot, '/\\' );
$coreAutoload = $coreRoot . '/autoload.php';

if ( ! is_file( $coreAutoload ) ) {
	throw new RuntimeException(
		'PHPStan requires the certified RAN Booster production source. '
		. 'Set RAN_BOOSTER_CORE_PATH to the exact certified Core checkout.'
	);
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require $coreAutoload;
require dirname( __DIR__ ) . '/autoload.php';
