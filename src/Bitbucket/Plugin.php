<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;

final class Plugin {
	public static function boot(): void {
		$plugin = new self();

		add_action( 'ran_booster_register_providers', array( $plugin, 'registerProvider' ) );
		add_filter( 'ran_booster_documentation_sections_after_provider_bb', array( $plugin, 'documentationSections' ), 10, 3 );
		add_action( 'admin_notices', array( $plugin, 'renderCompatibilityNotice' ) );
	}

	public function registerProvider( object $registry ): void {
		if ( ! self::hasCompatibleCore() || ! $registry instanceof ProviderRegistry ) {
			return;
		}

		$registry->registerWithCredentialStore(
			'bb',
			static function ( ProviderCredentialStore $credentials ): RepositoryProvider {
				$api      = new BitbucketApiClient();
				$loader   = new BitbucketCredentialLoader( $credentials );
				$browser  = new BitbucketRepositoryBrowser( $loader, $api );
				$archives = new BitbucketArchivePreparer( $loader, $api );
				$webhooks = new BitbucketWebhookNormalizer( $credentials );

				return new BitbucketProvider(
					new BitbucketCredentialValidator( $loader, $api ),
					$browser,
					$archives,
					$webhooks
				);
			}
		);
	}

	/** @param array<int, array{id: string, summary: string, content: callable}> $sections */
	public function documentationSections( array $sections, string $documentationUrl, string $scope ): array {
		if ( ! self::hasCompatibleCore() ) {
			return $sections;
		}

		unset( $documentationUrl, $scope );
		$sections[] = array(
			'id'      => 'ran-booster-documentation-bitbucket-cloud',
			'summary' => __( 'Bitbucket Cloud add-on', 'ran-booster-bitbucket' ),
			'content' => static function (): void {
				require dirname( __DIR__, 2 ) . '/views/documentation.php';
			},
		);

		return $sections;
	}

	public function renderCompatibilityNotice(): void {
		if ( ! current_user_can( 'activate_plugins' ) || self::hasCompatibleCore() ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'RAN Booster Bitbucket Cloud requires a compatible RAN Booster installation.', 'ran-booster-bitbucket' )
			. '</p></div>';
	}

	private static function hasCompatibleCore(): bool {
		return ( ! defined( 'RAN_BOOSTER_RUNTIME_MODE' ) || 'single_site_supported' === RAN_BOOSTER_RUNTIME_MODE )
			&& defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' )
			&& 9 === RAN_BOOSTER_PROVIDER_API_VERSION
			&& defined( 'RAN_BOOSTER_ADDON_API_VERSION' )
			&& 14 === RAN_BOOSTER_ADDON_API_VERSION;
	}
}
