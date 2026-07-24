<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\GitHub\RepositoryBrowser as GitHubRepositoryBrowser;
use RAN\RepositoryProvider\Bitbucket\BitbucketApiClient;
use RAN\RepositoryProvider\Bitbucket\BitbucketArchivePreparer;
use RAN\RepositoryProvider\Bitbucket\BitbucketCredentialLoader;
use RAN\RepositoryProvider\Bitbucket\BitbucketCredentialValidator;
use RAN\RepositoryProvider\Bitbucket\BitbucketProvider;
use RAN\RepositoryProvider\Bitbucket\BitbucketRepositoryBrowser;
use RAN\RepositoryProvider\Bitbucket\BitbucketWebhookNormalizer;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\GitHubProvider;
use RAN\RepositoryProvider\GitHubWebhookNormalizer;
use RAN\RepositoryProvider\InvalidProvider;
use RAN\RepositoryProvider\InvalidProviderCode;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\PublicRepositoryBrowseMetadata;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryBrowser;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\UnknownProvider;
use RAN\RepositoryProvider\UnsupportedProviderCapability;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\Secrets\SecretsFile;

final class ProviderRegistryTest extends TestCase {

	public function testItRegistersGitHubAndBitbucketProvidersInOrder(): void {
		$github    = $this->provider( ProviderCode::parse( 'gh' ), 'GitHub' );
		$bitbucket = $this->provider( ProviderCode::parse( 'bb' ), 'Bitbucket' );
		$registry  = new ProviderRegistry( array( $github, $bitbucket ) );

		self::assertSame( $github, $registry->get( 'gh' ) );
		self::assertSame( $bitbucket, $registry->get( ProviderCode::parse( 'bb' ) ) );
		self::assertSame( array( 'gh', 'bb' ), array_keys( $registry->all() ) );
		self::assertSame( 'GitHub', $registry->metadata()['gh']->label );
		self::assertSame( 'Bitbucket', $registry->metadata()['bb']->label );
	}

	public function testConcreteGitHubProviderAdvertisesOperationalCapabilities(): void {
		$provider = $this->githubProvider();

		self::assertSame(
			array(
				'code'                => 'gh',
				'label'               => 'GitHub',
				'repository_url_base' => 'https://github.com/',
				'owner_label'         => 'Owner',
			),
			$provider->getMetadata()->toArray()
		);
		self::assertInstanceOf( RepositoryProvider::class, $provider );
		self::assertInstanceOf( RepositoryBrowser::class, $provider );
		self::assertInstanceOf( CredentialedPublicRepositoryBrowser::class, $provider );
		self::assertTrue( $provider->getPublicRepositoryBrowseMetadata()->supportsProviderDefaultProfile );
		self::assertInstanceOf( WebhookNormalizer::class, $provider );
	}

	public function testConcreteBitbucketProviderAdvertisesOperationalCapabilities(): void {
		$provider = $this->bitbucketProvider();

		self::assertSame(
			array(
				'code'                => 'bb',
				'label'               => 'Bitbucket',
				'repository_url_base' => 'https://bitbucket.org/',
				'owner_label'         => 'Workspace',
			),
			$provider->getMetadata()->toArray()
		);
		self::assertInstanceOf( RepositoryProvider::class, $provider );
		self::assertInstanceOf( RepositoryBrowser::class, $provider );
		self::assertInstanceOf( CredentialedPublicRepositoryBrowser::class, $provider );
		self::assertTrue( $provider->getPublicRepositoryBrowseMetadata()->supportsProviderDefaultProfile );
		self::assertInstanceOf( WebhookNormalizer::class, $provider );
	}

	public function testDuplicateProvidersAreRejected(): void {
		$registry = new ProviderRegistry( array( $this->provider( ProviderCode::parse( 'gh' ), 'GitHub' ) ) );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'Repository provider is already registered.' );

		$registry->register( $this->provider( ProviderCode::parse( 'gh' ), 'Duplicate' ) );
	}

	public function testValidButUnregisteredProviderCodesAreRejected(): void {
		$registry = new ProviderRegistry();

		$this->expectException( UnknownProvider::class );

		$registry->get( 'github' );
	}

	public function testMalformedProviderCodesAreRejected(): void {
		$registry = new ProviderRegistry();

		$this->expectException( InvalidProviderCode::class );

		$registry->get( 'GitHub!' );
	}

	public function testUnregisteredProvidersAreRejected(): void {
		$registry = new ProviderRegistry( array( $this->githubProvider() ) );

		$this->expectException( UnknownProvider::class );

		$registry->get( ProviderCode::parse( 'bb' ) );
	}

	public function testInvalidProviderMetadataIsRejected(): void {
		$this->expectException( InvalidProvider::class );

		new ProviderMetadata( ProviderCode::parse( 'gh' ), '  ', 'https://github.com/', 'Owner' );
	}

	public function testProviderMetadataNormalizesLabelsAndRepositoryUrlBase(): void {
		$metadata = new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			' GitHub ',
			' https://EXAMPLE.test/api/v1 ',
			' Owner '
		);

		self::assertSame( 'GitHub', $metadata->label );
		self::assertSame( 'Owner', $metadata->ownerLabel );
		self::assertSame( 'https://example.test/api/v1/', $metadata->repositoryUrlBase );
	}

	/**
	 * @return list<array{string}>
	 */
	public static function invalidRepositoryUrlBases(): array {
		return array(
			array( 'https://' ),
			array( 'https://user@example.test/' ),
			array( 'https://user:password@example.test/' ),
			array( 'https://example.test/?token=sensitive' ),
			array( 'https://example.test/#fragment' ),
			array( 'http://example.test/' ),
		);
	}

	#[DataProvider( 'invalidRepositoryUrlBases' )]
	public function testProviderMetadataRejectsUnsafeRepositoryUrlBases( string $url ): void {
		$this->expectException( InvalidProvider::class );

		new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', $url, 'Owner' );
	}

	public function testCapabilitiesResolveOnlyWhenTheProviderImplementsThem(): void {
		$provider = new class() implements RepositoryProvider, RepositoryBrowser {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}

			public function browseRepositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
				return new RepositoryBrowseResult( array() );
			}
		};
		$registry = new ProviderRegistry( array( $provider ) );

		self::assertSame(
			$provider,
			$registry->requireCapability( ProviderCode::parse( 'gh' ), RepositoryBrowser::class )
		);
	}

	public function testCredentialedPublicBrowsingResolvesOnlyForTheNewOptionalCapability(): void {
		$provider = new class() implements RepositoryProvider, CredentialedPublicRepositoryBrowser {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}

			public function getPublicRepositoryBrowseMetadata(): PublicRepositoryBrowseMetadata {
				return new PublicRepositoryBrowseMetadata( true );
			}

			public function browseRepositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
				return new RepositoryBrowseResult( array() );
			}
		};
		$registry = new ProviderRegistry( array( $provider ) );

		self::assertSame(
			$provider,
			$registry->requireCapability( ProviderCode::parse( 'gh' ), CredentialedPublicRepositoryBrowser::class )
		);
		self::assertTrue(
			$provider->getPublicRepositoryBrowseMetadata()->supportsProviderDefaultProfile
		);
	}

	public function testOrdinaryRepositoryBrowserDoesNotGainCredentialedPublicBrowsing(): void {
		$provider = new class() implements RepositoryProvider, RepositoryBrowser {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'fixture' ), 'Fixture', 'https://example.test/', 'Owner' );
			}

			public function browseRepositories( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
				return new RepositoryBrowseResult( array() );
			}
		};
		$registry = new ProviderRegistry( array( $provider ) );

		$this->expectException( UnsupportedProviderCapability::class );

		$registry->requireCapability( ProviderCode::parse( 'fixture' ), CredentialedPublicRepositoryBrowser::class );
	}

	public function testMissingProviderCapabilitiesFailClosed(): void {
		$registry = new ProviderRegistry( array( $this->provider( ProviderCode::parse( 'gh' ), 'GitHub' ) ) );

		$this->expectException( UnsupportedProviderCapability::class );

		$registry->requireCapability( ProviderCode::parse( 'gh' ), WebhookNormalizer::class );
	}

	public function testUnknownCapabilityContractsAreRejected(): void {
		$registry = new ProviderRegistry( array( $this->githubProvider() ) );

		$this->expectException( UnsupportedProviderCapability::class );

		$registry->requireCapability( ProviderCode::parse( 'gh' ), RepositoryProvider::class );
	}

	private function provider( ProviderCode $code, string $label ): RepositoryProvider {
		return new readonly class( $code, $label ) implements RepositoryProvider {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function __construct(
				private ProviderCode $code,
				private string $label
			) {
			}

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata(
					$this->code,
					$this->label,
					'https://example.test/',
					'Owner'
				);
			}
		};
	}

	private function githubProvider(): GitHubProvider {
		$secrets = new SecretsFile( null, array() );

		return new GitHubProvider( $secrets, new GitHubRepositoryBrowser( $secrets ), new GitHubWebhookNormalizer( $secrets ) );
	}

	private function bitbucketProvider(): BitbucketProvider {
		$secrets = new SecretsFile( null, array() );
		$store   = new BitbucketProviderCredentialStore( $secrets );
		$loader  = new BitbucketCredentialLoader( $store );
		$api     = new BitbucketApiClient();

		return new BitbucketProvider(
			new BitbucketCredentialValidator( $loader, $api ),
			new BitbucketRepositoryBrowser( $loader, $api ),
			new BitbucketArchivePreparer( $loader, $api ),
			new BitbucketWebhookNormalizer( $store )
		);
	}
}
