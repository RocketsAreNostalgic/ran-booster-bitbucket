<?php

declare(strict_types=1);

$coreRoot = getenv( 'RAN_BOOSTER_CORE_PATH' );
$coreRoot = false === $coreRoot || '' === $coreRoot
	? dirname( __DIR__ ) . '/../ran-booster'
	: rtrim( $coreRoot, '/\\' );
$coreAutoload = $coreRoot . '/vendor/autoload.php';

if ( ! is_file( $coreAutoload ) ) {
	throw new RuntimeException(
		'PHPStan requires a compatible RAN Booster checkout with Composer dependencies. '
		. 'Set RAN_BOOSTER_CORE_PATH or run composer install in ' . $coreRoot . '.'
	);
}

require $coreAutoload;
require dirname( __DIR__ ) . '/autoload.php';
