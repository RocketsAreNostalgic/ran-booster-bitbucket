<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket\Tests\RepositoryProvider;

require_once __DIR__ . '/BitbucketCredentialValidatorWordPressFunctions.php';

use LogicException;
use PHPUnit\Framework\TestCase;
use RAN\Booster\Bitbucket\BitbucketApiClient;
use RAN\Booster\Bitbucket\BitbucketArchivePreparer;
use RAN\Booster\Bitbucket\BitbucketCredentialLoader;
use RAN\Booster\Bitbucket\BitbucketCredentialValidationTransportError;
use RAN\Booster\Bitbucket\BitbucketCredentialValidator;
use RAN\Booster\Bitbucket\BitbucketDiagnostics;
use RAN\Booster\Bitbucket\BitbucketProvider;
use RAN\Booster\Bitbucket\BitbucketRepositoryBrowser;
use RAN\Booster\Bitbucket\BitbucketWebhookNormalizer;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\Secrets\SecretsFile;
use RuntimeException;

final class BitbucketDiagnosticsBoundaryTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the PHPUnit lifecycle override signature.
	protected function setUp(): void {
		parent::setUp();

		\RAN\Booster\Bitbucket\bitbucket_repository_http_queue( array() );
	}

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

	public function test_unexpected_diagnostic_failures_carry_throwable_class_and_code_without_leaking_messages(): void {
		$credential_error = new LogicException( 'credential-secret-canary', 71 );
		$repository_error = new LogicException( 'repository-secret-canary', 72 );
		$secrets          = new class( $credential_error, $repository_error ) extends SecretsFile {
			private int $calls = 0;

			public function __construct(
				private LogicException $credential_error,
				private LogicException $repository_error
			) {
				parent::__construct( null, array() );
			}

			/** @return array<string, mixed>|null */
			public function credential_material( ProviderCode|string $provider, ?string $id = null ): ?array {
				unset( $provider, $id );
				++$this->calls;
				throw 1 === $this->calls ? $this->credential_error : $this->repository_error;
			}
		};

		$diagnostics = $this->diagnostics( $secrets );
		$results     = $diagnostics->diagnose( new ProviderDiagnosticRequest( 'profile', 'acme/plugin' ) );

		self::assertCount( 2, $results );
		self::assertSame( ProviderDiagnosticResult::WARNING, $results[0]->status );
		self::assertSame( 'bb.credential.unavailable', $results[0]->code );
		self::assertSame( $credential_error, $results[0]->failure );
		self::assertSame( LogicException::class, $results[0]->failure::class );
		self::assertSame( 71, $results[0]->failure->getCode() );
		self::assertStringNotContainsString( 'credential-secret-canary', implode( ' ', $results[0]->to_array() ) );

		self::assertSame( ProviderDiagnosticResult::WARNING, $results[1]->status );
		self::assertSame( 'bb.repository.unavailable', $results[1]->code );
		self::assertSame( $repository_error, $results[1]->failure );
		self::assertSame( LogicException::class, $results[1]->failure::class );
		self::assertSame( 72, $results[1]->failure->getCode() );
		self::assertStringNotContainsString( 'repository-secret-canary', implode( ' ', $results[1]->to_array() ) );
	}

	public function test_repository_diagnostic_distinguishes_401_403_4xx_and_5xx_and_carries_unexpected_throwables(): void {
		$fixtures = array(
			'401'             => array(
				'response'        => $this->http_response( 401, '{"error":"secret-401-canary"}' ),
				'status'          => ProviderDiagnosticResult::FAILED,
				'code'            => 'bb.repository.credential_rejected',
				'carries_failure' => false,
				'failure_code'    => null,
			),
			'403'             => array(
				'response'        => $this->http_response( 403, '{"error":"secret-403-canary"}' ),
				'status'          => ProviderDiagnosticResult::FAILED,
				'code'            => 'bb.repository.denied',
				'carries_failure' => false,
				'failure_code'    => null,
			),
			'404'             => array(
				'response'        => $this->http_response( 404, '{"error":"secret-404-canary"}' ),
				'status'          => ProviderDiagnosticResult::FAILED,
				'code'            => 'bb.repository.not_found',
				'carries_failure' => false,
				'failure_code'    => null,
			),
			'429'             => array(
				'response'        => $this->http_response( 429, '{"error":"secret-429-canary"}' ),
				'status'          => ProviderDiagnosticResult::WARNING,
				'code'            => 'bb.repository.rate_limited',
				'carries_failure' => false,
				'failure_code'    => null,
			),
			'400'             => array(
				'response'        => $this->http_response( 400, '{"error":"secret-400-canary"}' ),
				'status'          => ProviderDiagnosticResult::FAILED,
				'code'            => 'bb.repository.request_invalid',
				'carries_failure' => true,
				'failure_code'    => 400,
			),
			'422 empty repo'  => array(
				'response'        => $this->http_response( 200, '{"uuid":"{empty}","full_name":"acme/plugin","is_private":false,"mainbranch":null}' ),
				'status'          => ProviderDiagnosticResult::FAILED,
				'code'            => 'bb.repository.request_invalid',
				'carries_failure' => true,
				'failure_code'    => 422,
			),
			'500'             => array(
				'response'        => $this->http_response( 500, '{"error":"secret-500-canary"}' ),
				'status'          => ProviderDiagnosticResult::WARNING,
				'code'            => 'bb.repository.server_error',
				'carries_failure' => true,
				'failure_code'    => 502,
			),
			'transport error' => array(
				'response'        => new BitbucketCredentialValidationTransportError(),
				'status'          => ProviderDiagnosticResult::WARNING,
				'code'            => 'bb.repository.server_error',
				'carries_failure' => true,
				'failure_code'    => 502,
			),
		);

		foreach ( $fixtures as $name => $fixture ) {
			$context = (string) $name;
			\RAN\Booster\Bitbucket\bitbucket_repository_http_queue( array( $fixture['response'] ) );
			$results = $this->diagnostics( new BitbucketCredentialValidationSecretsStub( array() ) )
				->diagnose( new ProviderDiagnosticRequest( null, 'acme/plugin' ) );

			$repository_result = $results[1];
			self::assertSame( $fixture['status'], $repository_result->status, $context );
			self::assertSame( $fixture['code'], $repository_result->code, $context );
			self::assertStringNotContainsString( 'canary', implode( ' ', $repository_result->to_array() ), $context );

			if ( $fixture['carries_failure'] ) {
				self::assertInstanceOf( RuntimeException::class, $repository_result->failure, $context );
				self::assertSame( $fixture['failure_code'], $repository_result->failure->getCode(), $context );
			} else {
				self::assertNull( $repository_result->failure, $context );
			}
		}
	}

	private function diagnostics( SecretsFile $secrets ): BitbucketDiagnostics {
		$store  = new BitbucketProviderCredentialStore( $secrets );
		$loader = new BitbucketCredentialLoader( $store );
		$api    = new BitbucketApiClient();

		return new BitbucketDiagnostics(
			new BitbucketCredentialValidator( $loader, $api ),
			new BitbucketRepositoryBrowser( $loader, $api )
		);
	}

	/** @return array<string, mixed> */
	private function http_response( int $status, string $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		);
	}
}
