<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\Booster\Bitbucket\BitbucketApiClient;
use RAN\Booster\Bitbucket\BitbucketArchivePreparer;
use RAN\Booster\Bitbucket\BitbucketCredentialLoader;
use RAN\Booster\Bitbucket\BitbucketCredentialValidator;
use RAN\Booster\Bitbucket\BitbucketDiagnostics;
use RAN\Booster\Bitbucket\BitbucketProvider;
use RAN\Booster\Bitbucket\BitbucketRepositoryBrowser;
use RAN\Booster\Bitbucket\BitbucketWebhookNormalizer;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderCredentialStore;

final class BitbucketDiagnosticsBoundaryTest extends TestCase {

	public function testCredentialLoaderAcceptsOnlyTheProviderBoundStore(): void {
		$parameter = ( new \ReflectionMethod( BitbucketCredentialLoader::class, '__construct' ) )->getParameters()[0] ?? null;

		self::assertNotNull( $parameter );
		self::assertSame( ProviderCredentialStore::class, (string) $parameter->getType() );
	}

	public function testProviderAndDiagnosticsExposeNoLoggingDependency(): void {
		$providerTypes = array_map(
			static fn ( \ReflectionParameter $parameter ): string => (string) $parameter->getType(),
			( new \ReflectionMethod( BitbucketProvider::class, '__construct' ) )->getParameters()
		);
		$diagnosticTypes = array_map(
			static fn ( \ReflectionParameter $parameter ): string => (string) $parameter->getType(),
			( new \ReflectionMethod( BitbucketDiagnostics::class, '__construct' ) )->getParameters()
		);

		self::assertNotContains( 'RAN\\AddOn\\Logging\\LoggingFacade', $providerTypes );
		self::assertNotContains( 'RAN\\AddOn\\Logging\\LoggingFacade', $diagnosticTypes );
	}

	public function testNonSuccessDiagnosticsRemainBoundedTypedResults(): void {
		$secrets = new BitbucketCredentialValidationSecretsStub( array() );
		$store   = new BitbucketProviderCredentialStore( $secrets );
		$loader  = new BitbucketCredentialLoader( $store );
		$api     = new BitbucketApiClient();
		$provider = new BitbucketProvider(
			new BitbucketCredentialValidator( $loader, $api ),
			new BitbucketRepositoryBrowser( $loader, $api ),
			new BitbucketArchivePreparer( $loader, $api ),
			new BitbucketWebhookNormalizer( $store )
		);

		$results = $provider->getProviderDiagnostics()->diagnose( new ProviderDiagnosticRequest() );

		self::assertSame( array( 'bb.credential.not_configured', 'bb.repository.not_configured' ), array_column( $results, 'code' ) );
		self::assertStringNotContainsString( 'token', serialize( $results ) );
	}
}
