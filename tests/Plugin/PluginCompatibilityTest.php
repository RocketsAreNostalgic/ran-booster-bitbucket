<?php

declare(strict_types=1);

namespace Tests\Plugin;

use PHPUnit\Framework\TestCase;

final class PluginCompatibilityTest extends TestCase {

	public function testItFailsClosedWithoutBooster(): void {
		$result = $this->runFixture( 'absent' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertFalse( $result['provider_loaded'] );
		self::assertFalse( $result['registered'] );
	}

	public function testItFailsClosedWithAnIncompatibleProviderApi(): void {
		$result = $this->runFixture( 'incompatible' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertFalse( $result['provider_loaded'] );
		self::assertFalse( $result['registered'] );
	}

	public function testItRegistersOnlyAgainstProviderApiFiveAndKeepsReleaseCatalogOptional(): void {
		$result = $this->runFixture( 'compatible' );

		self::assertSame( 1, $result['provider_callbacks'] );
		self::assertTrue( $result['registered'] );
		self::assertSame( 'bb', $result['provider_code'] );
		self::assertTrue( $result['credential_store_was_scoped'] );
		self::assertFalse( $result['implements_release_catalog'] );
		self::assertSame( 0, $result['remote_calls'] );
	}

	public function testDeactivatedAddOnDoesNotContributeAProviderHook(): void {
		$result = $this->runFixture( 'inactive' );

		self::assertSame( 0, $result['provider_callbacks'] );
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
