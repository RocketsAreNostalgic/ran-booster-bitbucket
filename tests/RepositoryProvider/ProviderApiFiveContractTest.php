<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\Provider\ProviderCapability;
use RAN\RepositoryProvider\ReleaseCatalog;
use RAN\RepositoryProvider\ReleaseCatalogCandidate;
use RAN\RepositoryProvider\ReleaseCatalogListResult;
use RAN\RepositoryProvider\ReleaseCatalogRequest;
use RAN\RepositoryProvider\ReleaseCatalogResolveResult;
use RAN\RepositoryProvider\ReleaseCatalogResolvedRelease;
use RAN\RepositoryProvider\ReleaseCatalogResultCode;
use RAN\RepositoryProvider\ReleaseCatalogRevalidationCode;
use RAN\RepositoryProvider\ReleaseCatalogRevalidationResult;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderWebhookProfileReader;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryWebhookSettingsLink;

final class ProviderApiFiveContractTest extends TestCase {

	public function testReleaseCatalogueUsesTheOptionalCapabilityMarkerAndTypedSafeResults(): void {
		self::assertTrue( is_a( ReleaseCatalog::class, ProviderCapability::class, true ) );
		self::assertTrue( is_a( RepositoryWebhookSettingsLink::class, ProviderCapability::class, true ) );
		self::assertTrue( is_a( ProviderCredentialStore::class, ProviderWebhookProfileReader::class, true ) );

		$request = new ReleaseCatalogRequest( $this->repository(), 2 );
		self::assertSame( 2, $request->limit );

		$candidate = new ReleaseCatalogCandidate( 'release-42', 'v1.2.3', 'Version 1.2.3', '2026-07-24T12:34:56Z' );
		$listed    = new ReleaseCatalogListResult( ReleaseCatalogResultCode::AVAILABLE, array( $candidate ) );
		self::assertSame( array( $candidate ), $listed->releases );

		$resolved = new ReleaseCatalogResolvedRelease( 'release-42', 'v1.2.3', '0123456789abcdef0123456789abcdef01234567' );
		self::assertSame( $resolved, ( new ReleaseCatalogResolveResult( ReleaseCatalogResultCode::AVAILABLE, $resolved ) )->release );
		self::assertSame(
			ReleaseCatalogRevalidationCode::STALE,
			( new ReleaseCatalogRevalidationResult( ReleaseCatalogRevalidationCode::STALE ) )->code
		);
	}

	public function testReleaseCatalogueRejectsUnsafeOrUnboundedValues(): void {
		$this->expectException( InvalidArgumentException::class );
		new ReleaseCatalogRequest( $this->repository(), ReleaseCatalogRequest::MAX_RELEASES + 1 );
	}

	public function testReleaseCatalogueResultCannotAttachDataToAFailureCode(): void {
		$this->expectException( InvalidArgumentException::class );
		new ReleaseCatalogListResult(
			ReleaseCatalogResultCode::UNAVAILABLE,
			array( new ReleaseCatalogCandidate( 'release-42', 'v1.2.3', 'Version 1.2.3', '2026-07-24T12:34:56Z' ) )
		);
	}

	public function testBitbucketProtocolClassesHaveNoCoreSecretFileDependency(): void {
		$base = dirname( __DIR__, 2 ) . '/RAN/RepositoryProvider/Bitbucket/';

		foreach ( array( 'BitbucketCredentialLoader.php', 'BitbucketWebhookNormalizer.php' ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static source-boundary test.
			$source = file_get_contents( $base . $file );
			self::assertIsString( $source );
			self::assertStringNotContainsString( 'SecretsFile', $source );
			self::assertStringNotContainsString( 'webhookProfiles(', $source );
		}
	}

	private function repository(): RepositoryReference {
		return new RepositoryReference( 'group/project', 'provider:group/project', true, 'primary' );
	}
}
