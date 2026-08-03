<?php

declare(strict_types=1);

namespace Tests\Plugin;

use PHPUnit\Framework\TestCase;

final class PluginCompatibilityTest extends TestCase {

	public function testPluginHeaderDeclaresBoosterAsItsNativeDependency(): void {
		$plugin = file_get_contents( dirname( __DIR__, 2 ) . '/ran-booster-bitbucket.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release metadata contract.

		self::assertIsString( $plugin );
		self::assertStringContainsString( 'Requires Plugins: ran-booster', $plugin );
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
	}

	public function testItFailsClosedWithImmediateOldProviderAndCurrentAddOnApi(): void {
		$this->assertTupleFailsClosedInBothLoadOrders( 'provider-seven-addon-fourteen' );
	}

	public function testItFailsClosedWithCurrentProviderAndImmediateOldAddOnApi(): void {
		$this->assertTupleFailsClosedInBothLoadOrders( 'provider-eight-addon-thirteen' );
	}

	public function testItFailsClosedWithTheImmediateOldProviderAndAddOnTuple(): void {
		$this->assertTupleFailsClosedInBothLoadOrders( 'provider-seven-addon-thirteen' );
	}

	public function testItRegistersOnlyAgainstProviderApiEightWithoutClaimingOptionalCapabilities(): void {
		$result = $this->runFixture( 'compatible-core-first' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertSame( 1, $result['documentation_sections'] );
		self::assertSame( 2, $result['admin_interaction_api_version'] );
		self::assertSame( 0, $result['admin_interaction_callbacks'] );
		self::assertTrue( $result['markers_defined_when_loaded'] );
		self::assertTrue( $result['registered'] );
		self::assertSame( 'bb', $result['provider_code'] );
		self::assertTrue( $result['credential_store_was_scoped'] );
		self::assertSame( 0, $result['credential_store_reads'] );
		self::assertFalse( $result['implements_release_catalog'] );
		self::assertFalse( $result['implements_webhook_fitness'] );
		self::assertFalse( $result['implements_webhook_management'] );
		self::assertSame( 0, $result['remote_calls'] );
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
		self::assertSame( 0, $result['credential_store_reads'] );
		self::assertFalse( $result['implements_release_catalog'] );
		self::assertFalse( $result['implements_webhook_fitness'] );
		self::assertFalse( $result['implements_webhook_management'] );
		self::assertSame( 0, $result['remote_calls'] );
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
		self::assertStringContainsString( 'Deactivation, deletion and provider cleanup', $guide );
		self::assertStringContainsString( 'Private releases and support', $guide );
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
		foreach ( array( $mode => true, $mode . '-addon-first' => false ) as $fixtureMode => $markersDefinedWhenLoaded ) {
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
