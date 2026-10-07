<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket\Tests\Plugin;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class CurrentCoreBoundaryTest extends TestCase {
	public function test_runtime_uses_the_required_api_tuple_and_provider_registration_contract(): void {
		$plugin = file_get_contents( dirname( __DIR__, 2 ) . '/src/Bitbucket/Plugin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local compatibility contract.

		self::assertIsString( $plugin );
		self::assertStringContainsString( '14 === RAN_BOOSTER_PROVIDER_API_VERSION', $plugin );
		self::assertStringContainsString( '17 === RAN_BOOSTER_ADDON_API_VERSION', $plugin );
		self::assertStringContainsString( 'AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence', $plugin );
		self::assertStringContainsString( 'ProviderRegistrationContext $registration_context', $plugin );
		self::assertStringNotContainsString( 'interface_exists( AuthenticatedWebhookDeliveryEvidenceReader::class )', $plugin );
		self::assertStringNotContainsString( 'class_exists( ProviderRegistrationContext::class )', $plugin );
	}

	public function test_repository_tests_use_only_the_pinned_core_production_autoloader(): void {
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

		$certified_core = file_get_contents( $root . '/tests/fixtures/certified-core-checkout.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local certification contract.
		self::assertIsString( $certified_core );
		self::assertStringContainsString( 'ran-booster-core-certification', $certified_core );
		self::assertStringContainsString( 'rev-parse HEAD', $certified_core );

		$workflow = file_get_contents( $root . '/.github/workflows/quality.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local CI contract.
		self::assertIsString( $workflow );
		self::assertStringNotContainsString( 'Install Core development dependencies', $workflow );
		self::assertStringContainsString( 'Install certified Core production dependencies', $workflow );
		self::assertStringContainsString( 'composer install --no-dev --no-interaction --prefer-dist --no-progress', $workflow );
		self::assertStringContainsString( 'RAN_BOOSTER_CORE_VENDOR_AUTOLOAD', $workflow );
		self::assertStringNotContainsString( "working-directory: ran-booster\n        run: composer install\n", $workflow );

		foreach ( array( 'tests/bootstrap.php', 'tests/phpstan-bootstrap.php', 'tests/fixtures/plugin-lifecycle.php' ) as $path ) {
			$fixture = file_get_contents( $root . '/' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture-boundary contract.
			self::assertIsString( $fixture );
			self::assertStringContainsString( 'RAN_BOOSTER_CORE_VENDOR_AUTOLOAD', $fixture, $path );
		}
		self::assertStringContainsString( 'run: composer check:host', $workflow );
	}

	public function test_certified_core_resolver_rejects_different_git_checkout(): void {
		$root     = dirname( __DIR__, 2 );
		$previous = getenv( 'RAN_BOOSTER_CORE_PATH' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Test the certified-host environment override and restore the previous process value.
		putenv( 'RAN_BOOSTER_CORE_PATH=' . $root );

		try {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'requires the exact configured Core checkout' );
			\ran_booster_bitbucket_certified_core_root();
		} finally {
			if ( false === $previous ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Test the certified-host environment override and restore the previous process value.
				putenv( 'RAN_BOOSTER_CORE_PATH' );
			} else {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Test the certified-host environment override and restore the previous process value.
				putenv( 'RAN_BOOSTER_CORE_PATH=' . $previous );
			}
		}
	}

	public function test_no_repository_test_imports_core_owned_test_fixtures(): void {
		$root = dirname( __DIR__, 2 );
		// phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- Split a forbidden-fixture sentinel so the recursive source scan does not match its own test.
		$core_container_fixture = 'core-container-' . 'fixture.php';
		// phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- Split a forbidden-fixture sentinel so the recursive source scan does not match its own test.
		$archive_fixture = '/tests/RepositoryProvider/AuthenticatedPreparedArchive' . 'WordPressFunctions.php';
		$files           = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root . '/tests', FilesystemIterator::SKIP_DOTS )
		);

		/** @var SplFileInfo $file */
		foreach ( $files as $file ) {
			if ( ! $file->isFile() || ! in_array( $file->getExtension(), array( 'php', 'sh' ), true ) ) {
				continue;
			}

			$content = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture-boundary contract.
			self::assertIsString( $content );
			self::assertStringNotContainsString( $core_container_fixture, $content, $file->getPathname() );
			self::assertStringNotContainsString( $archive_fixture, $content, $file->getPathname() );
		}
	}

	public function test_installed_proof_binds_core_archive_to_canonical_certification(): void {
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
		self::assertStringContainsString( 'RAN_BOOSTER_CORE_ARCHIVE_SOURCE_COMMIT', $proof );
		self::assertStringContainsString( 'archive-source-commit', $proof );
	}
}
