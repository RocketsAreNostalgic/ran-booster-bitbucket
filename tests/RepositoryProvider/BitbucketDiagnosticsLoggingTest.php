<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\AddOn\Logging\LoggingFacade;
use RAN\Booster\Bitbucket\BitbucketApiClient;
use RAN\Booster\Bitbucket\BitbucketArchivePreparer;
use RAN\Booster\Bitbucket\BitbucketCredentialLoader;
use RAN\Booster\Bitbucket\BitbucketCredentialValidator;
use RAN\Booster\Bitbucket\BitbucketDiagnostics;
use RAN\Booster\Bitbucket\BitbucketProvider;
use RAN\Booster\Bitbucket\BitbucketRepositoryBrowser;
use RAN\Booster\Bitbucket\BitbucketWebhookNormalizer;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;

final class BitbucketDiagnosticsLoggingTest extends TestCase {

	public function testProviderAndDiagnosticsRequireTheCoreLoggingFacade(): void {
		$providerLogging    = ( new \ReflectionMethod( BitbucketProvider::class, '__construct' ) )->getParameters()[4];
		$diagnosticsLogging = ( new \ReflectionMethod( BitbucketDiagnostics::class, '__construct' ) )->getParameters()[2];

		foreach ( array( $providerLogging, $diagnosticsLogging ) as $parameter ) {
			self::assertSame( LoggingFacade::class, (string) $parameter->getType() );
			self::assertFalse( $parameter->allowsNull() );
			self::assertFalse( $parameter->isOptional() );
		}
	}

	public function testNonSuccessDiagnosticOutcomesUseTheInjectedCoreFacade(): void {
		$logger = new class() implements LoggingFacade {
			/** @var list<array{message: string, context: array<string, mixed>}> */
			public array $entries = array();

			public function log( string $message, array $context = array() ): void {
				$this->entries[] = compact( 'message', 'context' );
			}

			public function logException( string $message, \Throwable $exception, array $context = array() ): void {
				$this->entries[] = compact( 'message', 'context' );
			}
		};
		$secrets = new BitbucketCredentialValidationSecretsStub( array() );
		$store   = new BitbucketProviderCredentialStore( $secrets );
		$loader  = new BitbucketCredentialLoader( $store );
		$api     = new BitbucketApiClient();
		$provider = new BitbucketProvider(
			new BitbucketCredentialValidator( $loader, $api ),
			new BitbucketRepositoryBrowser( $loader, $api ),
			new BitbucketArchivePreparer( $loader, $api ),
			new BitbucketWebhookNormalizer( $store ),
			$logger
		);

		$provider->getProviderDiagnostics()->diagnose( new ProviderDiagnosticRequest() );

		self::assertCount( 2, $logger->entries );
		self::assertSame( 'bb.credential.not_configured', $logger->entries[0]['context']['outcome_code'] );
		self::assertSame( 'bb.repository.not_configured', $logger->entries[1]['context']['outcome_code'] );
		self::assertArrayNotHasKey( 'token', $logger->entries[0]['context'] );
	}
}
