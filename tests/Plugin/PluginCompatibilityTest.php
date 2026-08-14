<?php

declare(strict_types=1);

namespace Tests\Plugin;

use PHPUnit\Framework\TestCase;

final class PluginCompatibilityTest extends TestCase {

	public function testPluginHeaderDeclaresBoosterAsItsNativeDependency(): void {
		$plugin = file_get_contents( dirname( __DIR__, 2 ) . '/ran-booster-bitbucket.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release metadata contract.

		self::assertIsString( $plugin );
		self::assertStringContainsString( 'Requires Plugins: ran-booster', $plugin );
		self::assertStringContainsString( 'Update URI: https://github.com/RocketsAreNostalgic/ran-booster-bitbucket', $plugin );
	}

	public function testExtensionRecordMatchesThePluginIdentityAndExactApiTuple(): void {
		$root       = dirname( __DIR__, 2 );
		$composer   = json_decode(
			(string) file_get_contents( $root . '/composer.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local extension record contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$entrypoint = file_get_contents( $root . '/ran-booster-bitbucket.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local extension record contract.
		$plugin     = file_get_contents( $root . '/src/Bitbucket/Plugin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local extension record contract.

		self::assertIsArray( $composer );
		self::assertIsString( $entrypoint );
		self::assertIsString( $plugin );
		self::assertSame(
			array(
				'schema'            => 1,
				'id'                => 'ran-booster-bitbucket',
				'name'              => 'RAN Booster Bitbucket Cloud',
				'plugin-basename'   => 'ran-booster-bitbucket/ran-booster-bitbucket.php',
				'repository'        => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket',
				'update-uri'        => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket',
				'availability'      => 'free',
				'requires-wordpress'=> '7.0',
				'requires-php'      => '8.2',
				'booster-apis'      => array( 'required' => array( 'provider' => 9, 'addon' => 15 ), 'optional' => array() ),
				'maturity'          => 'beta',
				'documentation-uri' => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket#readme',
				'support-uri'       => 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/issues',
				'security-uri'      => null,
				'readiness'         => 'public-release-required',
			),
			$composer['extra']['ran-booster-extension'] ?? null
		);
		self::assertStringContainsString( 'Plugin Name: RAN Booster Bitbucket Cloud', $entrypoint );
		self::assertStringContainsString( 'Update URI: https://github.com/RocketsAreNostalgic/ran-booster-bitbucket', $entrypoint );
		self::assertStringContainsString( '9 === RAN_BOOSTER_PROVIDER_API_VERSION', $plugin );
		self::assertStringContainsString( '15 === RAN_BOOSTER_ADDON_API_VERSION', $plugin );
	}

	public function testEntrypointBootsOneFinalStatelessCompositionRoot(): void {
		$root       = dirname( __DIR__, 2 );
		$entrypoint = file_get_contents( $root . '/ran-booster-bitbucket.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local architecture contract.
		require_once $root . '/autoload.php';
		$plugin = new \ReflectionClass( \RAN\Booster\Bitbucket\Plugin::class );

		self::assertIsString( $entrypoint );
		self::assertStringContainsString( '\\RAN\\Booster\\Bitbucket\\Plugin::boot();', $entrypoint );
		self::assertTrue( $plugin->isFinal() );
		self::assertSame( array(), $plugin->getProperties() );
	}

	public function testReleasePleaseOwnsEveryPluginVersionSource(): void {
		$root     = dirname( __DIR__, 2 );
		$plugin   = file_get_contents( $root . '/ran-booster-bitbucket.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release metadata contract.
		$readme   = file_get_contents( $root . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release metadata contract.
		$composer = json_decode(
			(string) file_get_contents( $root . '/composer.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release metadata contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$manifest = json_decode(
			(string) file_get_contents( $root . '/.release-please-manifest.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release metadata contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		self::assertIsString( $plugin );
		self::assertIsString( $readme );
		self::assertIsArray( $composer );
		self::assertIsArray( $manifest );
		self::assertMatchesRegularExpression(
			'/x-release-please-start-version\\R \\* Version: ([^\\s]+)\\R \\* x-release-please-end/',
			$plugin
		);
		self::assertMatchesRegularExpression(
			'/<!-- x-release-please-start-version -->\\RStable tag: ([^\\s]+)\\R<!-- x-release-please-end -->/',
			$readme
		);

		preg_match( '/\\* Version: ([^\\s]+)/', $plugin, $pluginVersion );
		preg_match( '/Stable tag: ([^\\s]+)/', $readme, $readmeVersion );

		self::assertSame( $manifest['.'], $pluginVersion[1] ?? null );
		self::assertSame( $manifest['.'], $readmeVersion[1] ?? null );
		self::assertArrayNotHasKey( 'version', $composer );
	}

	public function testItFailsClosedWithoutBooster(): void {
		$result = $this->runFixture( 'absent' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertSame( 1, $result['documentation_filters'] );
		self::assertSame( 0, $result['documentation_sections'] );
		self::assertSame( '', $result['documentation'] );
		self::assertFalse( $result['provider_loaded'] );
		self::assertFalse( $result['registered'] );
		self::assertStringContainsString( 'requires a compatible RAN Booster installation', $result['compatibility_notice'] );
		self::assertSame( 0, $result['remote_calls'] );
	}

	public function testCompatibilityNoticeRequiresPluginActivationCapability(): void {
		$result = $this->runFixture( 'absent-unprivileged' );

		self::assertSame( '', $result['compatibility_notice'] );
		self::assertFalse( $result['registered'] );
		self::assertSame( 0, $result['remote_calls'] );
	}

	public function testItFailsClosedWithImmediateOldProviderAndCurrentAddOnApi(): void {
		$this->assertTupleFailsClosedInBothLoadOrders( 'provider-eight-addon-fifteen' );
	}

	public function testItFailsClosedWithCurrentProviderAndImmediateOldAddOnApi(): void {
		$this->assertTupleFailsClosedInBothLoadOrders( 'provider-nine-addon-fourteen' );
	}

	public function testItFailsClosedWithTheImmediateOldProviderAndAddOnTuple(): void {
		$this->assertTupleFailsClosedInBothLoadOrders( 'provider-eight-addon-fourteen' );
	}

	public function testItRegistersOnlyAgainstProviderApiNineWithoutClaimingOptionalCapabilities(): void {
		$result = $this->runFixture( 'compatible-core-first' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertSame( 1, $result['documentation_sections'] );
		self::assertSame( 2, $result['admin_interaction_api_version'] );
		self::assertSame( 0, $result['admin_interaction_callbacks'] );
		self::assertTrue( $result['markers_defined_when_loaded'] );
		self::assertTrue( $result['registered'] );
		self::assertSame( 'bb', $result['provider_code'] );
		self::assertTrue( $result['credential_store_was_scoped'] );
		self::assertTrue( $result['delivery_evidence_was_scoped'] );
		self::assertTrue( $result['owner_requires_managed_target'] );
		self::assertSame( 200, $result['navigation_slot'] );
		self::assertSame( 0, $result['credential_store_reads'] );
		self::assertFalse( $result['implements_release_catalog'] );
		self::assertFalse( $result['implements_webhook_fitness'] );
		self::assertFalse( $result['implements_webhook_management'] );
		self::assertSame( 1, $result['remote_calls'] );
		self::assertSame( 'example/reference-plugin', $result['operation_locator'] );
		self::assertSame( '', $result['compatibility_notice'] );
	}

	public function testItRegistersWhenTheAddOnLoadsBeforeCompatibleCoreMarkers(): void {
		$result = $this->runFixture( 'compatible-addon-first' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertSame( 1, $result['documentation_sections'] );
		self::assertSame( 2, $result['admin_interaction_api_version'] );
		self::assertSame( 0, $result['admin_interaction_callbacks'] );
		self::assertFalse( $result['markers_defined_when_loaded'] );
		self::assertTrue( $result['registered'] );
		self::assertSame( 'bb', $result['provider_code'] );
		self::assertTrue( $result['credential_store_was_scoped'] );
		self::assertTrue( $result['delivery_evidence_was_scoped'] );
		self::assertTrue( $result['owner_requires_managed_target'] );
		self::assertSame( 200, $result['navigation_slot'] );
		self::assertSame( 0, $result['credential_store_reads'] );
		self::assertFalse( $result['implements_release_catalog'] );
		self::assertFalse( $result['implements_webhook_fitness'] );
		self::assertFalse( $result['implements_webhook_management'] );
		self::assertSame( 1, $result['remote_calls'] );
		self::assertSame( 'example/reference-plugin', $result['operation_locator'] );
		self::assertSame( '', $result['compatibility_notice'] );
	}

	public function testUnsupportedMultisiteCallbacksStayInertDespiteCompatibleApis(): void {
		$result = $this->runFixture( 'unsupported-multisite' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertSame( 0, $result['documentation_sections'] );
		self::assertSame( '', $result['documentation'] );
		self::assertFalse( $result['provider_loaded'] );
		self::assertFalse( $result['registered'] );
		self::assertFalse( $result['credential_store_was_scoped'] );
		self::assertSame( 0, $result['remote_calls'] );
		self::assertStringContainsString( 'requires a compatible RAN Booster installation', $result['compatibility_notice'] );
	}

	public function testCompatibleGenerationRendersOneCompleteNonInteractiveGuide(): void {
		$result = $this->runFixture( 'compatible' );
		$guide  = $result['documentation'];

		self::assertIsString( $guide );
		self::assertStringNotContainsString( '<details', $guide );
		self::assertStringContainsString( 'Repositories: Read (read:repository:bitbucket)', $guide );
		self::assertStringContainsString( 'trusted credential-bearing provider code', $guide );
		self::assertStringContainsString( 'may request individual Bitbucket credentials from that namespace more than once', $guide );
		self::assertStringContainsString( 'cannot enumerate or read another provider’s credentials', $guide );
		self::assertStringContainsString( 'not confidentiality from hostile PHP', $guide );
		self::assertStringContainsString( 'Connect a package', $guide );
		self::assertStringContainsString( 'Set up Push-to-Deploy manually', $guide );
		self::assertStringContainsString( 'Move or recover a package with Transporter', $guide );
		self::assertStringContainsString( 'import the copied material, use a saved Bitbucket credential on that site, or leave its packages unchanged', $guide );
		self::assertStringContainsString( 'There is no credential or anonymous fallback', $guide );
		self::assertStringContainsString( 'does not assess token permissions', $guide );
		self::assertStringContainsString( 'does not remove, revoke or rotate the source API token', $guide );
		self::assertStringContainsString( 'Deactivation, deletion and provider cleanup', $guide );
		self::assertStringContainsString( 'Releases and support', $guide );
		self::assertStringNotContainsString( '<form', $guide );
		self::assertStringNotContainsString( '<input', $guide );
		self::assertStringNotContainsString( 'wp_nonce', $guide );
		self::assertStringNotContainsString( 'admin_post_', $guide );
	}

	public function testDeactivatedAddOnDoesNotContributeAProviderHook(): void {
		$result = $this->runFixture( 'inactive' );

		self::assertSame( 0, $result['provider_callbacks'] );
		self::assertSame( 0, $result['documentation_filters'] );
		self::assertSame( 0, $result['documentation_sections'] );
		self::assertSame( '', $result['documentation'] );
		self::assertFalse( $result['registered'] );
	}

	private function assertTupleFailsClosedInBothLoadOrders( string $mode ): void {
		foreach (
			array(
				$mode                   => true,
				$mode . '-addon-first' => false,
			) as $fixtureMode => $markersDefinedWhenLoaded
		) {
			$result = $this->runFixture( $fixtureMode );

			self::assertSame( 1, $result['provider_callbacks'], $fixtureMode );
			self::assertSame( 0, $result['documentation_sections'], $fixtureMode );
			self::assertSame( '', $result['documentation'], $fixtureMode );
			self::assertSame( $markersDefinedWhenLoaded, $result['markers_defined_when_loaded'], $fixtureMode );
			self::assertFalse( $result['provider_loaded'], $fixtureMode );
			self::assertFalse( $result['registered'], $fixtureMode );
			self::assertSame( 0, $result['credential_store_reads'], $fixtureMode );
			self::assertSame( 0, $result['remote_calls'], $fixtureMode );
		}
	}

	/** @return array<string, bool|int|string|null> */
	private function runFixture( string $mode ): array {
		$fixture = dirname( __DIR__ ) . '/fixtures/plugin-lifecycle.php';
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $fixture ) . ' ' . escapeshellarg( $mode );
		$output  = shell_exec( $command );

		self::assertIsString( $output );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode -- Fixture output is local, structured test data.
		$result = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $result );

		return $result;
	}
}
