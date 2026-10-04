<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Isolated PHPUnit namespace matches the test autoload contract.
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

	public function test_credential_loader_accepts_only_the_provider_bound_store(): void {
		$parameter = ( new \ReflectionMethod( BitbucketCredentialLoader::class, '__construct' ) )->getParameters()[0] ?? null;

		self::assertNotNull( $parameter );
		self::assertSame( ProviderCredentialStore::class, (string) $parameter->getType() );
	}

	public function test_provider_and_diagnostics_expose_no_logging_dependency(): void {
		$provider_types   = array_map(
			static fn ( \ReflectionParameter $parameter ): string => (string) $parameter->getType(),
			( new \ReflectionMethod( BitbucketProvider::class, '__construct' ) )->getParameters()
		);
		$diagnostic_types = array_map(
			static fn ( \ReflectionParameter $parameter ): string => (string) $parameter->getType(),
			( new \ReflectionMethod( BitbucketDiagnostics::class, '__construct' ) )->getParameters()
		);

		self::assertNotContains( 'RAN\\AddOn\\Logging\\LoggingFacade', $provider_types );
		self::assertNotContains( 'RAN\\AddOn\\Logging\\LoggingFacade', $diagnostic_types );
	}

	public function test_non_success_diagnostics_remain_bounded_typed_results(): void {
		$secrets  = new BitbucketCredentialValidationSecretsStub( array() );
		$store    = new BitbucketProviderCredentialStore( $secrets );
		$loader   = new BitbucketCredentialLoader( $store );
		$api      = new BitbucketApiClient();
		$provider = new BitbucketProvider(
			new BitbucketCredentialValidator( $loader, $api ),
			new BitbucketRepositoryBrowser( $loader, $api ),
			new BitbucketArchivePreparer( $loader, $api ),
			new BitbucketWebhookNormalizer( $store )
		);

		$results = $provider->get_provider_diagnostics()->diagnose( new ProviderDiagnosticRequest() );

		self::assertSame( array( 'bb.credential.not_configured', 'bb.repository.not_configured' ), array_column( $results, 'code' ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Negative boundary test searches serialized results for credential leakage; no untrusted data is unserialized.
		self::assertStringNotContainsString( 'token', serialize( $results ) );
	}
}
