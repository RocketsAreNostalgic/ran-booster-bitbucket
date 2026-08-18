<?php

/**
 * Plugin Name: RAN Booster Bitbucket Cloud
 * Plugin URI: https://github.com/RocketsAreNostalgic/ran-booster-bitbucket
 * Description: Bitbucket Cloud provider for RAN Booster.
 * x-release-please-start-version
 * Version: 0.1.0-beta.11
 * x-release-please-end
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Requires Plugins: ran-booster
 * Update URI: https://github.com/RocketsAreNostalgic/ran-booster-bitbucket
 * Author: Rockets Are Nostalgic
 * Author URI: https://github.com/RocketsAreNostalgic
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ran-booster-bitbucket
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require __DIR__ . '/autoload.php';

\RAN\Booster\Bitbucket\Plugin::boot();
