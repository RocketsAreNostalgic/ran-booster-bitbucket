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

	public function testItFailsClosedWithoutBooster(): void {
		$result = $this->runFixture( 'absent' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertSame( 1, $result['documentation_callbacks'] );
		self::assertSame( '', $result['documentation'] );
		self::assertFalse( $result['provider_loaded'] );
		self::assertFalse( $result['registered'] );
	}

	public function testItFailsClosedWithAnIncompatibleProviderApi(): void {
		$result = $this->runFixture( 'incompatible' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertSame( 1, $result['documentation_callbacks'] );
		self::assertSame( '', $result['documentation'] );
		self::assertFalse( $result['provider_loaded'] );
		self::assertFalse( $result['registered'] );
	}

	public function testItFailsClosedWithAddOnApiSix(): void {
		$result = $this->runFixture( 'incompatible-addon' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertSame( 1, $result['documentation_callbacks'] );
		self::assertSame( '', $result['documentation'] );
		self::assertFalse( $result['provider_loaded'] );
		self::assertFalse( $result['registered'] );
	}

	public function testItRegistersOnlyAgainstProviderApiFiveAndKeepsReleaseCatalogOptional(): void {
		$result = $this->runFixture( 'compatible' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertSame( 1, $result['documentation_callbacks'] );
		self::assertTrue( $result['registered'] );
		self::assertSame( 'bb', $result['provider_code'] );
		self::assertTrue( $result['credential_store_was_scoped'] );
		self::assertFalse( $result['implements_release_catalog'] );
		self::assertSame( 0, $result['remote_calls'] );
	}

	public function testCompatibleGenerationRendersOneCompleteNonInteractiveGuide(): void {
		$result = $this->runFixture( 'compatible' );
		$guide  = $result['documentation'];

		self::assertIsString( $guide );
		self::assertSame( 1, substr_count( $guide, 'id="ran-booster-documentation-bitbucket-cloud"' ) );
		self::assertStringContainsString( 'Repositories: Read (read:repository:bitbucket)', $guide );
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
		self::assertSame( 0, $result['documentation_callbacks'] );
		self::assertSame( '', $result['documentation'] );
		self::assertFalse( $result['registered'] );
	}

	/** @return array<string, bool|int|string> */
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
