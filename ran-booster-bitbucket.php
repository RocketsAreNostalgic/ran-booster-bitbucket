<?php

/**
 * Plugin Name: RAN Booster Bitbucket Cloud
 * Plugin URI: https://github.com/RocketsAreNostalgic/ran-booster-bitbucket
 * Description: Bitbucket Cloud provider for RAN Booster.
 * x-release-please-start-version
 * Version: 0.1.0-beta.1
 * x-release-please-end
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Requires Plugins: ran-booster
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

add_action(
	'ran_booster_register_providers',
	static function ( object $registry ): void {
		if ( ( defined( 'RAN_BOOSTER_RUNTIME_MODE' )
				&& 'single_site_supported' !== RAN_BOOSTER_RUNTIME_MODE )
			|| ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' )
			|| 8 !== RAN_BOOSTER_PROVIDER_API_VERSION
			|| ! defined( 'RAN_BOOSTER_ADDON_API_VERSION' )
			|| 14 !== RAN_BOOSTER_ADDON_API_VERSION
			|| ! $registry instanceof \RAN\RepositoryProvider\ProviderRegistry ) {
			return;
		}

		$registry->registerWithCredentialStore(
			'bb',
			static function ( \RAN\RepositoryProvider\ProviderCredentialStore $credentials ): \RAN\RepositoryProvider\RepositoryProvider {
				$api      = new \RAN\Booster\Bitbucket\BitbucketApiClient();
				$loader   = new \RAN\Booster\Bitbucket\BitbucketCredentialLoader( $credentials );
				$browser  = new \RAN\Booster\Bitbucket\BitbucketRepositoryBrowser( $loader, $api );
				$archives = new \RAN\Booster\Bitbucket\BitbucketArchivePreparer( $loader, $api );
				$webhooks = new \RAN\Booster\Bitbucket\BitbucketWebhookNormalizer( $credentials );

				return new \RAN\Booster\Bitbucket\BitbucketProvider(
					new \RAN\Booster\Bitbucket\BitbucketCredentialValidator( $loader, $api ),
					$browser,
					$archives,
					$webhooks
				);
			}
		);
	}
);

add_filter(
	'ran_booster_documentation_sections_after_provider_bb',
	static function ( array $sections, string $documentationUrl, string $scope ): array {
		if ( ( defined( 'RAN_BOOSTER_RUNTIME_MODE' )
				&& 'single_site_supported' !== RAN_BOOSTER_RUNTIME_MODE )
			|| ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' )
			|| 8 !== RAN_BOOSTER_PROVIDER_API_VERSION
			|| ! defined( 'RAN_BOOSTER_ADDON_API_VERSION' )
			|| 14 !== RAN_BOOSTER_ADDON_API_VERSION ) {
			return $sections;
		}

		unset( $documentationUrl, $scope );
		$sections[] = array(
			'id'      => 'ran-booster-documentation-bitbucket-cloud',
			'summary' => __( 'Bitbucket Cloud add-on', 'ran-booster-bitbucket' ),
			'content' => static function (): void {
				require __DIR__ . '/views/documentation.php';
			},
		);

		return $sections;
	},
	10,
	3
);

add_action(
	'admin_notices',
	static function (): void {
		if ( defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' ) && 8 === RAN_BOOSTER_PROVIDER_API_VERSION
			&& defined( 'RAN_BOOSTER_ADDON_API_VERSION' ) && 14 === RAN_BOOSTER_ADDON_API_VERSION ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'RAN Booster Bitbucket Cloud requires a compatible RAN Booster installation.', 'ran-booster-bitbucket' )
			. '</p></div>';
	}
);
