<?php

declare(strict_types=1);

namespace Tests\Plugin;

use PHPUnit\Framework\TestCase;

final class CoreContractTest extends TestCase {

	public function testConfiguredCoreCheckoutPublishesTheRequiredApiGeneration(): void {
		$coreRoot = getenv( 'RAN_BOOSTER_CORE_PATH' );
		$coreRoot = false === $coreRoot || '' === $coreRoot
			? dirname( __DIR__, 3 ) . '/ran-booster'
			: rtrim( $coreRoot, '/\\' );
		$entryFile         = $coreRoot . '/ran-booster.php';
		$documentationFile = $coreRoot . '/views/documentation.php';
		$core              = file_get_contents( $entryFile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local contract fixture.
		$documentation     = file_get_contents( $documentationFile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local contract fixture.

		self::assertIsString( $core, 'A RAN Booster entry file is required at ' . $entryFile );
		self::assertIsString( $documentation, 'A RAN Booster documentation view is required at ' . $documentationFile );
		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_PROVIDER_API_VERSION',\\s*6\\s*\\)/",
			$core
		);
		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_LOGGING_API_VERSION',\\s*1\\s*\\)/",
			$core
		);
		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_ADDON_API_VERSION',\\s*7\\s*\\)/",
			$core
		);
		self::assertStringContainsString( 'ran_booster_documentation_sections_after_provider_', $documentation );
	}
}
