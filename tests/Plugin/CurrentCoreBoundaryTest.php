<?php

declare(strict_types=1);

namespace Tests\Plugin;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class CurrentCoreBoundaryTest extends TestCase {
	public function testRuntimeUsesThePublishedApiTupleAndProviderRegistrationContract(): void {
		$plugin = file_get_contents( dirname( __DIR__, 2 ) . '/src/Bitbucket/Plugin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local compatibility contract.

		self::assertIsString( $plugin );
		self::assertStringContainsString( '10 === RAN_BOOSTER_PROVIDER_API_VERSION', $plugin );
		self::assertStringContainsString( '16 === RAN_BOOSTER_ADDON_API_VERSION', $plugin );
		self::assertStringContainsString( 'AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence', $plugin );
		self::assertStringContainsString( 'ProviderRegistrationContext $registrationContext', $plugin );
		self::assertStringNotContainsString( 'interface_exists( AuthenticatedWebhookDeliveryEvidenceReader::class )', $plugin );
		self::assertStringNotContainsString( 'class_exists( ProviderRegistrationContext::class )', $plugin );
	}

	public function testRepositoryTestsUseOnlyTheCertifiedCoreProductionAutoloader(): void {
		$root = dirname( __DIR__, 2 );

		foreach ( array( 'tests/bootstrap.php', 'tests/phpstan-bootstrap.php', 'tests/fixtures/plugin-lifecycle.php' ) as $path ) {
			$fixture = file_get_contents( $root . '/' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture-boundary contract.
			self::assertIsString( $fixture );
			self::assertStringContainsString( "/autoload.php'", $fixture, $path );
			self::assertStringNotContainsString( '/vendor/autoload.php', $fixture, $path );
		}

		foreach ( array( 'tests/bootstrap.php', 'tests/phpstan-bootstrap.php' ) as $path ) {
			$bootstrap = file_get_contents( $root . '/' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture-boundary contract.
			self::assertIsString( $bootstrap );
			self::assertStringContainsString( 'certified-core-checkout.php', $bootstrap, $path );
			self::assertStringContainsString( 'ran_booster_bitbucket_certified_core_root()', $bootstrap, $path );
		}

		$certifiedCore = file_get_contents( $root . '/tests/fixtures/certified-core-checkout.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local certification contract.
		self::assertIsString( $certifiedCore );
		self::assertStringContainsString( 'ran-booster-core-certification', $certifiedCore );
		self::assertStringContainsString( 'rev-parse HEAD', $certifiedCore );

		$workflow = file_get_contents( $root . '/.github/workflows/quality.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local CI contract.
		self::assertIsString( $workflow );
		self::assertStringNotContainsString( 'Install Core development dependencies', $workflow );
		self::assertStringNotContainsString( "working-directory: ran-booster\n        run: composer install", $workflow );
		self::assertStringContainsString( 'Run static analysis pilot', $workflow );
		self::assertStringContainsString( 'run: composer analyse', $workflow );
	}

	public function testCertifiedCoreResolverRejectsDifferentGitCheckout(): void {
		$root     = dirname( __DIR__, 2 );
		$previous = getenv( 'RAN_BOOSTER_CORE_PATH' );
		putenv( 'RAN_BOOSTER_CORE_PATH=' . $root );

		try {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'requires the exact certified Core checkout' );
			\ran_booster_bitbucket_certified_core_root();
		} finally {
			if ( false === $previous ) {
				putenv( 'RAN_BOOSTER_CORE_PATH' );
			} else {
				putenv( 'RAN_BOOSTER_CORE_PATH=' . $previous );
			}
		}
	}

	public function testNoRepositoryTestImportsCoreOwnedTestFixtures(): void {
		$root                 = dirname( __DIR__, 2 );
		$coreContainerFixture = 'core-container-' . 'fixture.php';
		$archiveFixture       = '/tests/RepositoryProvider/AuthenticatedPreparedArchive' . 'WordPressFunctions.php';
		$files                = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root . '/tests', FilesystemIterator::SKIP_DOTS )
		);

		/** @var SplFileInfo $file */
		foreach ( $files as $file ) {
			if ( ! $file->isFile() || ! in_array( $file->getExtension(), array( 'php', 'sh' ), true ) ) {
				continue;
			}

			$content = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture-boundary contract.
			self::assertIsString( $content );
			self::assertStringNotContainsString( $coreContainerFixture, $content, $file->getPathname() );
			self::assertStringNotContainsString( $archiveFixture, $content, $file->getPathname() );
		}
	}

	public function testInstalledProofBindsCoreArchiveToCanonicalCertification(): void {
		$root  = dirname( __DIR__, 2 );
		$smoke = file_get_contents( $root . '/tests/WordPress/bitbucket-installed-smoke.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local installed-proof contract.
		$proof = file_get_contents( $root . '/tests/WordPress/bitbucket-installed-proof.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local installed-proof contract.

		self::assertIsString( $smoke );
		self::assertIsString( $proof );
		self::assertStringNotContainsString( 'RAN_BOOSTER_CORE_SOURCE_PATH', $smoke . $proof );
		self::assertStringContainsString( 'ProviderRegistry(', $smoke );
		self::assertStringContainsString( 'AuthenticatedWebhookDeliveryEvidenceReader', $smoke );
		self::assertStringContainsString( 'bb.webhook.delivery_verified', $smoke );
		self::assertStringContainsString( 'ran-booster/ran-booster-release.json', $proof );
		self::assertStringContainsString( 'ran-booster-core-certification', $proof );
		self::assertStringContainsString( 'composer.json', $proof );
		self::assertStringContainsString( 'RAN_BOOSTER_CORE_TAG', $proof );
		self::assertStringContainsString( 'RAN_BOOSTER_CORE_COMMIT', $proof );
	}
}
