<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket\Tests\RepositoryProvider;

use LogicException;
use PHPUnit\Framework\TestCase;
use RAN\Booster\Bitbucket\BitbucketCredential;
use RAN\Booster\Bitbucket\BitbucketCredentialException;
use ReflectionClass;

final class BitbucketCredentialTest extends TestCase {

	private const WORKSPACE = 'acme-workspace';
	private const EMAIL     = 'synthetic-account@example.test';
	private const TOKEN     = 'synthetic-shape-token-0001';

	public function test_authorize_derives_basic_header_at_use_and_preserves_existing_headers(): void {
		$credential = $this->synthetic_credential();
		$arguments  = $credential->authorize(
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		self::assertSame( self::WORKSPACE, $credential->get_workspace() );
		self::assertSame( 15, $arguments['timeout'] );
		self::assertIsArray( $arguments['headers'] );
		self::assertSame( 'application/json', $arguments['headers']['Accept'] );
		self::assertSame( $this->expected_basic_header(), $arguments['headers']['Authorization'] );

		$this->expectException( BitbucketCredentialException::class );
		$credential->authorize( array( 'headers' => 'not-an-array' ) );
	}

	public function test_debug_info_dumps_and_export_never_expose_secret_material_or_header(): void {
		$credential = $this->synthetic_credential();

		self::assertSame( array( 'workspace' => self::WORKSPACE ), $credential->__debugInfo() );

		ob_start();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump -- Regression test verifying var_dump never leaks credential material.
		var_dump( $credential );
		$var_dump_output = ob_get_clean();
		self::assertIsString( $var_dump_output );
		self::assertStringContainsString( self::WORKSPACE, $var_dump_output );
		$this->assert_no_secret_leak( $var_dump_output, 'var_dump' );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Regression test verifying print_r never leaks credential material.
		$print_r_output = print_r( $credential, true );
		self::assertStringContainsString( self::WORKSPACE, $print_r_output );
		$this->assert_no_secret_leak( $print_r_output, 'print_r' );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Regression test verifying var_export never leaks credential material.
		$var_export_output = var_export( $credential, true );
		self::assertStringContainsString( self::WORKSPACE, $var_export_output );
		$this->assert_no_secret_leak( $var_export_output, 'var_export' );

		$authorizer = static fn ( array $arguments ): array => $credential->authorize( $arguments );
		ob_start();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump -- Regression test verifying closure capture var_dump never leaks credential material.
		var_dump( $authorizer );
		$closure_dump = ob_get_clean();
		self::assertIsString( $closure_dump );
		$this->assert_no_secret_leak( $closure_dump, 'closure var_dump' );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Regression test verifying closure capture var_export never leaks credential material.
		$closure_export = var_export( $authorizer, true );
		$this->assert_no_secret_leak( $closure_export, 'closure var_export' );

		$reflection = new ReflectionClass( $credential );
		foreach ( $reflection->getProperties() as $property ) {
			$value = $property->getValue( $credential );
			if ( is_string( $value ) ) {
				$this->assert_no_secret_leak( $value, 'property ' . $property->getName() );
			}
		}
	}

	public function test_serialize_and_unserialize_are_refused(): void {
		$credential = $this->synthetic_credential();

		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Regression test verifying serialize refuses BitbucketCredential.
			serialize( $credential );
			self::fail( 'Expected serialize(BitbucketCredential) to throw.' );
		} catch ( LogicException $exception ) {
			self::assertSame( 'Bitbucket credentials cannot be serialized.', $exception->getMessage() );
			$this->assert_no_secret_leak( $exception->getMessage(), 'serialize exception' );
		}

		$class_name = BitbucketCredential::class;
		$payload    = sprintf(
			'O:%d:"%s":1:{s:9:"workspace";s:%d:"%s";}',
			strlen( $class_name ),
			$class_name,
			strlen( self::WORKSPACE ),
			self::WORKSPACE
		);

		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Regression test verifying unserialize refuses BitbucketCredential.
			unserialize( $payload, array( 'allowed_classes' => array( BitbucketCredential::class ) ) );
			self::fail( 'Expected unserialize(BitbucketCredential) to throw.' );
		} catch ( LogicException $exception ) {
			self::assertSame( 'Bitbucket credentials cannot be unserialized.', $exception->getMessage() );
			$this->assert_no_secret_leak( $exception->getMessage(), 'unserialize exception' );
		}
	}

	public function test_clone_is_refused(): void {
		$credential = $this->synthetic_credential();

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'Bitbucket credentials cannot be cloned.' );

		$copy = clone $credential;
		unset( $copy );
	}

	private function synthetic_credential(): BitbucketCredential {
		return BitbucketCredential::from_material( self::WORKSPACE, self::EMAIL, self::TOKEN );
	}

	private function expected_basic_header(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Assert Bitbucket's required Basic wire value.
		return 'Basic ' . base64_encode( self::EMAIL . ':' . self::TOKEN );
	}

	private function assert_no_secret_leak( string $output, string $context ): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Compute synthetic Basic payload for negative leak assertions.
		$encoded = base64_encode( self::EMAIL . ':' . self::TOKEN );

		self::assertStringNotContainsString( self::TOKEN, $output, $context );
		self::assertStringNotContainsString( self::EMAIL, $output, $context );
		self::assertStringNotContainsString( $encoded, $output, $context );
		self::assertStringNotContainsString( 'Basic ', $output, $context );
	}
}
