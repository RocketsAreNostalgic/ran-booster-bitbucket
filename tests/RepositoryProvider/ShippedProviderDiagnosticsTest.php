<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once __DIR__ . '/../GitHub/RepositoryResolverWordPressFunctions.php';
require_once __DIR__ . '/../GitHub/RepositoryResolverSecretsStub.php';
require_once __DIR__ . '/BitbucketCredentialValidatorWordPressFunctions.php';
require_once __DIR__ . '/BitbucketCredentialValidationSecretsStub.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\GitHub\RepositoryBrowser as GitHubRepositoryBrowser;
use RAN\RepositoryProvider\Bitbucket\BitbucketApiClient;
use RAN\RepositoryProvider\Bitbucket\BitbucketCredentialLoader;
use RAN\RepositoryProvider\Bitbucket\BitbucketCredentialValidationTransportError;
use RAN\RepositoryProvider\Bitbucket\BitbucketCredentialValidator;
use RAN\RepositoryProvider\Bitbucket\BitbucketDiagnostics;
use RAN\RepositoryProvider\Bitbucket\BitbucketRepositoryBrowser;
use RAN\RepositoryProvider\GitHubDiagnostics;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use Tests\GitHub\RepositoryResolverSecretsStub;

final class ShippedProviderDiagnosticsTest extends TestCase {

	private const TOKEN = 'diagnostic-token-canary';

	/**
	 * @return list<array{string, callable(): \RAN\RepositoryProvider\ProviderDiagnostics, callable(): int}>
	 */
	public static function shippedProviderBaselineCases(): array {
		return array(
			array(
				'gh',
				static function (): \RAN\RepositoryProvider\ProviderDiagnostics {
					\RAN\GitHub\repository_resolver_http_reset(
						array(
							'response' => array( 'code' => 200 ),
							'body'     => '{"id":42,"full_name":"example/package","private":false,"default_branch":"main"}',
						)
					);

					return new GitHubDiagnostics( new GitHubRepositoryBrowser( new RepositoryResolverSecretsStub() ) );
				},
				static fn (): int => count( \RAN\GitHub\repository_resolver_http_requests() ),
			),
			array(
				'bb',
				static function (): \RAN\RepositoryProvider\ProviderDiagnostics {
					\RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_reset(
						array(
							'response' => array( 'code' => 200 ),
							'body'     => '{"uuid":"{fixture}","full_name":"example/package","is_private":false,"mainbranch":{"name":"main"}}',
						)
					);

					$secrets = new BitbucketCredentialValidationSecretsStub( array() );
					$loader  = new BitbucketCredentialLoader( new BitbucketProviderCredentialStore( $secrets ) );
					$api     = new BitbucketApiClient();

					return new BitbucketDiagnostics(
						new BitbucketCredentialValidator( $loader, $api ),
						new BitbucketRepositoryBrowser( $loader, $api )
					);
				},
				static fn (): int => count( \RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_requests() ),
			),
		);
	}

	#[DataProvider( 'shippedProviderBaselineCases' )]
	public function testShippedProvidersMeetTheSameDiagnosticBaseline( string $providerCode, callable $makeDiagnostics, callable $requestCount ): void {
		$request = new ProviderDiagnosticRequest( null, 'example/package' );
		$results = $makeDiagnostics()->diagnose( $request );

		self::assertCount( 2, $results );
		self::assertSame( 1, $request->getRemoteCalls() );
		self::assertSame( 1, $requestCount() );
		self::assertSame(
			array( ProviderDiagnosticResult::NOT_CONFIGURED, ProviderDiagnosticResult::PASSED ),
			array_map( static fn ( ProviderDiagnosticResult $result ): string => $result->status, $results )
		);

		foreach ( $results as $result ) {
			self::assertInstanceOf( ProviderDiagnosticResult::class, $result );
			self::assertStringStartsWith( $providerCode . '.', $result->code );
			self::assertNotSame( '', $result->message );
			self::assertNotSame( '', $result->remediation );
		}
	}

	public function testGitHubCredentialSuccessUsesTheProductionClientAndBudget(): void {
		\RAN\GitHub\repository_resolver_http_reset( $this->githubResponse( 200, array( 'login' => 'example' ) ) );
		$browser = new GitHubRepositoryBrowser( new RepositoryResolverSecretsStub( array( 'profile' => self::TOKEN ) ) );
		$request = new ProviderDiagnosticRequest( 'profile', null );

		$results = ( new GitHubDiagnostics( $browser ) )->diagnose( $request );
		$http    = \RAN\GitHub\repository_resolver_http_requests();

		self::assertSame( ProviderDiagnosticResult::PASSED, $results[0]->status );
		self::assertSame( 'gh.credential.valid', $results[0]->code );
		self::assertCount( 2, $results );
		self::assertSame( 1, $request->getRemoteCalls() );
		self::assertLessThanOrEqual( 10.0, $http[0]['arguments']['timeout'] );
		self::assertSame( 65536, $http[0]['arguments']['limit_response_size'] );
		self::assertStringNotContainsString( self::TOKEN, implode( ' ', $results[0]->toArray() ) );
	}

	/** @return list<array{int, array<string, mixed>, string, string}> */
	public static function githubCredentialFailures(): array {
		return array(
			array( 401, array(), ProviderDiagnosticResult::FAILED, 'gh.credential.invalid' ),
			array( 403, array(), ProviderDiagnosticResult::FAILED, 'gh.credential.invalid' ),
			array( 403, array( 'x-ratelimit-remaining' => '0' ), ProviderDiagnosticResult::WARNING, 'gh.credential.rate_limited' ),
			array( 429, array(), ProviderDiagnosticResult::WARNING, 'gh.credential.rate_limited' ),
			array( 404, array(), ProviderDiagnosticResult::WARNING, 'gh.credential.unavailable' ),
			array( 500, array(), ProviderDiagnosticResult::WARNING, 'gh.credential.unavailable' ),
		);
	}

	#[DataProvider( 'githubCredentialFailures' )]
	public function testGitHubCredentialFailuresAreSafelyClassified( int $status, array $headers, string $expectedStatus, string $code ): void {
		\RAN\GitHub\repository_resolver_http_reset( $this->githubResponse( $status, array(), $headers ) );
		$diagnostics = new GitHubDiagnostics(
			new GitHubRepositoryBrowser( new RepositoryResolverSecretsStub( array( 'profile' => self::TOKEN ) ) )
		);

		$result = $diagnostics->diagnose( new ProviderDiagnosticRequest( 'profile' ) )[0];

		self::assertSame( $expectedStatus, $result->status );
		self::assertSame( $code, $result->code );
		self::assertStringNotContainsString( self::TOKEN, implode( ' ', $result->toArray() ) );
	}

	public function testGitHubTransportFailureIsAWarning(): void {
		\RAN\GitHub\repository_resolver_http_reset( new \RAN\GitHub\RepositoryResolverWpError( 'http_request_failed' ) );
		$diagnostics = new GitHubDiagnostics(
			new GitHubRepositoryBrowser( new RepositoryResolverSecretsStub( array( 'profile' => self::TOKEN ) ) )
		);

		$result = $diagnostics->diagnose( new ProviderDiagnosticRequest( 'profile' ) )[0];

		self::assertSame( ProviderDiagnosticResult::WARNING, $result->status );
		self::assertSame( 'gh.credential.unavailable', $result->code );
		self::assertCount( 1, \RAN\GitHub\repository_resolver_http_requests() );
	}

	public function testGitHubInvalidCredentialResponseIsNotExposed(): void {
		\RAN\GitHub\repository_resolver_http_reset(
			$this->githubResponse( 200, array( 'error' => self::TOKEN ) )
		);

		$result = ( new GitHubDiagnostics(
			new GitHubRepositoryBrowser( new RepositoryResolverSecretsStub( array( 'profile' => self::TOKEN ) ) )
		) )->diagnose( new ProviderDiagnosticRequest( 'profile' ) )[0];

		self::assertSame( ProviderDiagnosticResult::WARNING, $result->status );
		self::assertSame( 'gh.credential.unavailable', $result->code );
		$this->assertResultIsRedacted( $result );
	}

	/** @return list<array{int, string, string}> */
	public static function githubRepositoryFailures(): array {
		return array(
			array( 401, ProviderDiagnosticResult::FAILED, 'gh.repository.denied' ),
			array( 403, ProviderDiagnosticResult::FAILED, 'gh.repository.denied' ),
			array( 404, ProviderDiagnosticResult::FAILED, 'gh.repository.not_found' ),
			array( 429, ProviderDiagnosticResult::WARNING, 'gh.repository.rate_limited' ),
			array( 503, ProviderDiagnosticResult::WARNING, 'gh.repository.unavailable' ),
		);
	}

	#[DataProvider( 'githubRepositoryFailures' )]
	public function testGitHubRepositoryFailuresAreSafelyClassified( int $status, string $expectedStatus, string $code ): void {
		\RAN\GitHub\repository_resolver_http_reset(
			$this->githubResponse( $status, array( 'token' => self::TOKEN ), array( 'x-upstream-canary' => self::TOKEN ) )
		);

		$result = ( new GitHubDiagnostics( new GitHubRepositoryBrowser( new RepositoryResolverSecretsStub() ) ) )
			->diagnose( new ProviderDiagnosticRequest( null, 'example/package' ) )[1];

		self::assertSame( $expectedStatus, $result->status );
		self::assertSame( $code, $result->code );
		$this->assertResultIsRedacted( $result );
		self::assertCount( 1, \RAN\GitHub\repository_resolver_http_requests() );
	}

	public function testGitHubDiagnosticRequestsAreBoundedAndDoNotUseDiscovery(): void {
		\RAN\GitHub\repository_resolver_http_reset( $this->githubResponse( 200, array( 'login' => 'example' ) ) );
		$credentialResult  = ( new GitHubDiagnostics(
			new GitHubRepositoryBrowser( new RepositoryResolverSecretsStub( array( 'profile' => self::TOKEN ) ) )
		) )->diagnose( new ProviderDiagnosticRequest( 'profile' ) )[0];
		$credentialRequest = \RAN\GitHub\repository_resolver_http_requests()[0];

		self::assertSame( 'gh.credential.valid', $credentialResult->code );
		self::assertSame( 'https://api.github.com/user', $credentialRequest['url'] );
		self::assertSame( 0, $credentialRequest['arguments']['redirection'] );
		self::assertTrue( $credentialRequest['arguments']['reject_unsafe_urls'] );
		self::assertSame( 65536, $credentialRequest['arguments']['limit_response_size'] );
		self::assertArrayNotHasKey( 'page', $credentialRequest['arguments'] );

		\RAN\GitHub\repository_resolver_http_reset(
			$this->githubResponse( 200, $this->githubRepositoryPayload() )
		);
		$repositoryResult  = ( new GitHubDiagnostics( new GitHubRepositoryBrowser( new RepositoryResolverSecretsStub() ) ) )
			->diagnose( new ProviderDiagnosticRequest( null, 'example/package' ) )[1];
		$repositoryRequest = \RAN\GitHub\repository_resolver_http_requests()[0];

		self::assertSame( 'gh.repository.reachable', $repositoryResult->code );
		self::assertSame( 'https://api.github.com/repos/example/package', $repositoryRequest['url'] );
		self::assertSame( 0, $repositoryRequest['arguments']['redirection'] );
		self::assertTrue( $repositoryRequest['arguments']['reject_unsafe_urls'] );
		self::assertSame( 65536, $repositoryRequest['arguments']['limit_response_size'] );
		self::assertStringNotContainsString( '/user/repos', $repositoryRequest['url'] );
	}

	public function testGitHubStopsAfterTheSharedCallBudgetWithoutAnotherRequest(): void {
		\RAN\GitHub\repository_resolver_http_reset( $this->githubResponse( 200, array( 'login' => 'example' ) ) );
		$request = new ProviderDiagnosticRequest( 'profile', 'example/package', 1 );

		$results = ( new GitHubDiagnostics(
			new GitHubRepositoryBrowser( new RepositoryResolverSecretsStub( array( 'profile' => self::TOKEN ) ) )
		) )->diagnose( $request );

		self::assertSame( 'gh.credential.valid', $results[0]->code );
		self::assertSame( 'gh.repository.budget_exhausted', $results[1]->code );
		self::assertSame( 1, $request->getRemoteCalls() );
		self::assertCount( 1, \RAN\GitHub\repository_resolver_http_requests() );
	}

	public function testGitHubRepositorySuccessIsIndependentOfCredentials(): void {
		\RAN\GitHub\repository_resolver_http_reset(
			$this->githubResponse(
				200,
				array(
					'id'             => 42,
					'full_name'      => 'example/package',
					'private'        => false,
					'default_branch' => 'main',
				)
			)
		);
		$request = new ProviderDiagnosticRequest( null, 'example/package' );

		$results = ( new GitHubDiagnostics( new GitHubRepositoryBrowser( new RepositoryResolverSecretsStub() ) ) )
			->diagnose( $request );

		self::assertSame( 'gh.repository.reachable', $results[1]->code );
		self::assertSame( 1, $request->getRemoteCalls() );
	}

	public function testBitbucketCredentialSuccessUsesProductionValidatorAndDiagnosticLimits(): void {
		\RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_reset(
			$this->bitbucketResponse( 200, '{"pagelen":1,"values":[]}' )
		);
		$diagnostics = $this->bitbucketDiagnostics();
		$request     = new ProviderDiagnosticRequest( 'profile' );

		$result = $diagnostics->diagnose( $request )[0];
		$http   = \RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_requests();

		self::assertSame( 'bb.credential.valid', $result->code );
		self::assertCount( 2, $diagnostics->diagnose( new ProviderDiagnosticRequest() ) );
		self::assertSame( 1, $request->getRemoteCalls() );
		self::assertLessThanOrEqual( 10.0, $http[0]['arguments']['timeout'] );
		self::assertSame( 65536, $http[0]['arguments']['limit_response_size'] );
	}

	/** @return list<array{mixed, string, string}> */
	public static function bitbucketCredentialFailures(): array {
		return array(
			array(
				array(
					'response' => array( 'code' => 401 ),
					'body'     => '{}',
				),
				ProviderDiagnosticResult::FAILED,
				'bb.credential.invalid',
			),
			array(
				array(
					'response' => array( 'code' => 403 ),
					'body'     => '{}',
				),
				ProviderDiagnosticResult::FAILED,
				'bb.credential.invalid',
			),
			array(
				array(
					'response' => array( 'code' => 404 ),
					'body'     => '{}',
				),
				ProviderDiagnosticResult::FAILED,
				'bb.credential.invalid',
			),
			array(
				array(
					'response' => array( 'code' => 429 ),
					'body'     => '{}',
				),
				ProviderDiagnosticResult::WARNING,
				'bb.credential.rate_limited',
			),
			array( new BitbucketCredentialValidationTransportError(), ProviderDiagnosticResult::WARNING, 'bb.credential.unavailable' ),
		);
	}

	#[DataProvider( 'bitbucketCredentialFailures' )]
	public function testBitbucketCredentialFailuresAreSafelyClassified( mixed $response, string $status, string $code ): void {
		\RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_reset( $response );

		$result = $this->bitbucketDiagnostics()->diagnose( new ProviderDiagnosticRequest( 'profile' ) )[0];

		self::assertSame( $status, $result->status );
		self::assertSame( $code, $result->code );
		self::assertStringNotContainsString( self::TOKEN, implode( ' ', $result->toArray() ) );
	}

	public function testBitbucketRepositorySuccessUsesTheSameBrowser(): void {
		\RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_reset(
			$this->bitbucketResponse(
				200,
				'{"uuid":"{fixture}","full_name":"workspace/package","is_private":false,"mainbranch":{"name":"main"}}'
			)
		);
		$request = new ProviderDiagnosticRequest( null, 'workspace/package' );

		$results = $this->bitbucketDiagnostics()->diagnose( $request );

		self::assertSame( 'bb.repository.reachable', $results[1]->code );
		self::assertCount( 2, $results );
		self::assertSame( 1, $request->getRemoteCalls() );
	}

	public function testBitbucketInvalidCredentialResponseIsNotExposed(): void {
		\RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_reset(
			$this->bitbucketResponse( 200, '{"error":"' . self::TOKEN . '"}' )
		);

		$result = $this->bitbucketDiagnostics()->diagnose( new ProviderDiagnosticRequest( 'profile' ) )[0];

		self::assertSame( ProviderDiagnosticResult::WARNING, $result->status );
		self::assertSame( 'bb.credential.unavailable', $result->code );
		$this->assertResultIsRedacted( $result );
	}

	/** @return list<array{int, string, string}> */
	public static function bitbucketRepositoryFailures(): array {
		return array(
			array( 401, ProviderDiagnosticResult::FAILED, 'bb.repository.denied' ),
			array( 403, ProviderDiagnosticResult::FAILED, 'bb.repository.denied' ),
			array( 404, ProviderDiagnosticResult::FAILED, 'bb.repository.not_found' ),
			array( 429, ProviderDiagnosticResult::WARNING, 'bb.repository.rate_limited' ),
			array( 503, ProviderDiagnosticResult::WARNING, 'bb.repository.unavailable' ),
		);
	}

	#[DataProvider( 'bitbucketRepositoryFailures' )]
	public function testBitbucketRepositoryFailuresAreSafelyClassified( int $status, string $expectedStatus, string $code ): void {
		\RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_reset(
			$this->bitbucketResponse( $status, '{"token":"' . self::TOKEN . '"}' )
		);

		$result = $this->bitbucketDiagnostics()->diagnose(
			new ProviderDiagnosticRequest( null, 'workspace/package' )
		)[1];

		self::assertSame( $expectedStatus, $result->status );
		self::assertSame( $code, $result->code );
		$this->assertResultIsRedacted( $result );
		self::assertCount( 1, \RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_requests() );
	}

	public function testBitbucketDiagnosticRequestsAreBoundedAndDoNotSweepPages(): void {
		\RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_reset(
			$this->bitbucketResponse( 200, '{"pagelen":1,"values":[]}' )
		);
		$credentialResult  = $this->bitbucketDiagnostics()->diagnose( new ProviderDiagnosticRequest( 'profile' ) )[0];
		$credentialRequest = \RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_requests()[0];

		self::assertSame( 'bb.credential.valid', $credentialResult->code );
		self::assertSame( 'https://api.bitbucket.org/2.0/repositories/workspace?pagelen=1', $credentialRequest['url'] );
		self::assertSame( 0, $credentialRequest['arguments']['redirection'] );
		self::assertTrue( $credentialRequest['arguments']['reject_unsafe_urls'] );
		self::assertSame( 65536, $credentialRequest['arguments']['limit_response_size'] );
		self::assertCount( 1, \RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_requests() );

		\RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_reset(
			$this->bitbucketResponse(
				200,
				'{"uuid":"{fixture}","full_name":"workspace/package","is_private":false,"mainbranch":{"name":"main"}}'
			)
		);
		$repositoryResult  = $this->bitbucketDiagnostics()->diagnose(
			new ProviderDiagnosticRequest( null, 'workspace/package' )
		)[1];
		$repositoryRequest = \RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_requests()[0];

		self::assertSame( 'bb.repository.reachable', $repositoryResult->code );
		self::assertSame( 0, $repositoryRequest['arguments']['redirection'] );
		self::assertTrue( $repositoryRequest['arguments']['reject_unsafe_urls'] );
		self::assertSame( 65536, $repositoryRequest['arguments']['limit_response_size'] );
		self::assertStringStartsWith( 'https://api.bitbucket.org/2.0/repositories/workspace/package?', $repositoryRequest['url'] );
		self::assertStringNotContainsString( 'next=', $repositoryRequest['url'] );
	}

	public function testBitbucketStopsAfterTheSharedCallBudgetWithoutAnotherRequest(): void {
		\RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_reset(
			$this->bitbucketResponse( 200, '{"pagelen":1,"values":[]}' )
		);
		$request = new ProviderDiagnosticRequest( 'profile', 'workspace/package', 1 );

		$results = $this->bitbucketDiagnostics()->diagnose( $request );

		self::assertSame( 'bb.credential.valid', $results[0]->code );
		self::assertSame( 'bb.repository.budget_exhausted', $results[1]->code );
		self::assertSame( 1, $request->getRemoteCalls() );
		self::assertCount( 1, \RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_requests() );
	}

	private function bitbucketDiagnostics(): BitbucketDiagnostics {
		$secrets = new BitbucketCredentialValidationSecretsStub(
			array(
				'profile' => array(
					'id'            => 'profile',
					'provider'      => 'bb',
					'label'         => 'Fixture',
					'kind'          => 'api-token',
					'configuration' => array(
						'workspace' => 'workspace',
						'email'     => 'test@example.test',
					),
					'secret'        => self::TOKEN,
					'source'        => 'file',
					'immutable'     => false,
				),
			)
		);
		$loader  = new BitbucketCredentialLoader( new BitbucketProviderCredentialStore( $secrets ) );
		$api     = new BitbucketApiClient();

		return new BitbucketDiagnostics(
			new BitbucketCredentialValidator( $loader, $api ),
			new BitbucketRepositoryBrowser( $loader, $api )
		);
	}

	private function assertResultIsRedacted( ProviderDiagnosticResult $result ): void {
		$resultText = implode( ' ', $result->toArray() );

		self::assertStringNotContainsString( self::TOKEN, $resultText );
		self::assertStringNotContainsString( 'x-upstream-canary', $resultText );
		self::assertStringNotContainsString( 'Authorization', $resultText );
	}

	/** @return array<string, int|string|bool> */
	private function githubRepositoryPayload(): array {
		return array(
			'id'             => 42,
			'full_name'      => 'example/package',
			'private'        => false,
			'default_branch' => 'main',
		);
	}

	/** @param array<string, mixed> $body @param array<string, string> $headers */
	private function githubResponse( int $status, array $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Runtime-neutral unit-test fixture.
			'body'     => json_encode( $body ),
		);
	}

	private function bitbucketResponse( int $status, string $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		);
	}
}
