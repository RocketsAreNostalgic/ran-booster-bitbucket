<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once dirname( __DIR__ ) . '/GitHub/RepositoryResolverWordPressFunctions.php';
require_once __DIR__ . '/AuthenticatedPreparedArchiveWordPressFunctions.php';
require_once __DIR__ . '/BitbucketCredentialValidatorWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\AuthenticatedPreparedArchive;
use RAN\GitHub\RepositoryBrowser as GitHubRepositoryBrowser;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\Bitbucket\BitbucketApiClient;
use RAN\RepositoryProvider\Bitbucket\BitbucketArchivePreparer;
use RAN\RepositoryProvider\Bitbucket\BitbucketCredentialLoader;
use RAN\RepositoryProvider\Bitbucket\BitbucketCredentialValidator;
use RAN\RepositoryProvider\Bitbucket\BitbucketProvider;
use RAN\RepositoryProvider\Bitbucket\BitbucketRepositoryBrowser;
use RAN\RepositoryProvider\Bitbucket\BitbucketWebhookNormalizer;
use RAN\RepositoryProvider\GitHubProvider;
use RAN\RepositoryProvider\GitHubWebhookNormalizer;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\StaleDeployment;
use RAN\RepositoryProvider\WebhookNormalizer;
use RuntimeException;
use Tests\GitHub\RepositoryResolverSecretsStub;

final class ExpectedHeadProviderConformanceTest extends TestCase {

	private const CURRENT = '0123456789abcdef0123456789abcdef01234567';
	private const STALE   = '89abcdef0123456789abcdef0123456789abcdef';

	protected function setUp(): void {
		parent::setUp();

		$this->resetHarness();
	}

	protected function tearDown(): void {
		$this->resetHarness();

		parent::tearDown();
	}

	public function testEveryShippedWebhookArchiveProviderEnforcesTheSharedExpectedHeadCases(): void {
		$providers = array_filter(
			$this->registry()->all(),
			static fn ( RepositoryProvider $provider ): bool => $provider instanceof WebhookNormalizer
		);

		self::assertSame( array( 'bb', 'gh' ), array_keys( $providers ) );

		foreach ( $providers as $code => $provider ) {
			$this->assertCase( $provider, $code, 'current', self::CURRENT, null );
			$this->assertCase( $provider, $code, 'stale', self::STALE, 409 );
			$this->assertCase( $provider, $code, 'missing', self::CURRENT, 404 );
			$this->assertCase( $provider, $code, 'provider-error', self::CURRENT, 502 );
		}
	}

	private function assertCase(
		RepositoryProvider $provider,
		string $code,
		string $case,
		string $commit,
		?int $expectedError
	): void {
		$this->queueCase( $code, $case );
		$request = $this->request( $code, $commit );

		try {
			$archive = $provider->prepareArchive( $request );
			if ( null !== $expectedError ) {
				self::fail( $code . ' must reject the ' . $case . ' expected-head case.' );
			}

			self::assertStringContainsString( self::CURRENT, $archive->getUrl(), $code );
			self::assertSame( self::CURRENT, $archive->getResolvedRef(), $code );
			$archive->verifyCurrentHead();
			$archive->cleanup();
		} catch ( RuntimeException $exception ) {
			if ( null === $expectedError ) {
				throw $exception;
			}

			self::assertSame( $expectedError, $exception->getCode(), $code . ':' . $case );
			if ( 'stale' === $case ) {
				self::assertInstanceOf( StaleDeployment::class, $exception, $code );
			}
		}

		$this->assertNoArchiveHooks( $code . ':' . $case );
		$expectedRequests = 'current' === $case
			? ( 'gh' === $code ? 3 : 2 )
			: ( 'gh' === $code ? 2 : 1 );
		self::assertSame( $expectedRequests, $this->requestCount( $code ), $code . ':' . $case );
	}

	private function registry(): ProviderRegistry {
		$githubSecrets        = new RepositoryResolverSecretsStub();
		$bitbucketSecrets     = new BitbucketRepositoryBrowserSecretsStub( array() );
		$bitbucketStore       = new BitbucketProviderCredentialStore( $bitbucketSecrets );
		$bitbucketCredentials = new BitbucketCredentialLoader( $bitbucketStore );
		$bitbucketApi         = new BitbucketApiClient();

		return new ProviderRegistry(
			array(
				new BitbucketProvider(
					new BitbucketCredentialValidator( $bitbucketCredentials, $bitbucketApi ),
					new BitbucketRepositoryBrowser( $bitbucketCredentials, $bitbucketApi ),
					new BitbucketArchivePreparer( $bitbucketCredentials, $bitbucketApi ),
					new BitbucketWebhookNormalizer( $bitbucketStore )
				),
				new GitHubProvider(
					$githubSecrets,
					new GitHubRepositoryBrowser( $githubSecrets ),
					new GitHubWebhookNormalizer( $githubSecrets )
				),
			)
		);
	}

	private function request( string $code, string $commit ): ArchiveRequest {
		return new ArchiveRequest(
			new RepositoryReference(
				'gh' === $code ? 'owner/example' : 'workspace/example',
				'gh' === $code ? '987654321' : '{repository-uuid}',
				false,
				null
			),
			$commit,
			'main'
		);
	}

	private function queueCase( string $code, string $case ): void {
		if ( 'gh' === $code ) {
			\RAN\GitHub\repository_resolver_http_queue(
				array(
					$this->githubIdentityResponse(),
					$this->githubBranchResponse( $case ),
					...( 'current' === $case ? array( $this->githubBranchResponse( $case ) ) : array() ),
				)
			);

			return;
		}

		\RAN\RepositoryProvider\Bitbucket\bitbucket_repository_http_queue(
			array(
				$this->bitbucketBranchResponse( $case ),
				...( 'current' === $case ? array( $this->bitbucketBranchResponse( $case ) ) : array() ),
			)
		);
	}

	/** @return array<string, mixed> */
	private function githubIdentityResponse(): array {
		return $this->response(
			200,
			array(
				'id'             => '987654321',
				'full_name'      => 'owner/example',
				'private'        => false,
				'default_branch' => 'main',
			)
		);
	}

	private function githubBranchResponse( string $case ): array {
		if ( 'missing' === $case ) {
			return $this->response( 404, array( 'message' => 'response-canary' ) );
		}
		if ( 'provider-error' === $case ) {
			return $this->response( 500, array( 'message' => 'response-canary' ) );
		}

		return $this->response(
			200,
			array(
				'name'   => 'main',
				'commit' => array( 'sha' => self::CURRENT ),
			)
		);
	}

	private function bitbucketBranchResponse( string $case ): array {
		if ( 'missing' === $case ) {
			return $this->response( 404, array( 'error' => 'response-canary' ) );
		}
		if ( 'provider-error' === $case ) {
			return $this->response( 500, array( 'error' => 'response-canary' ) );
		}

		return $this->response(
			200,
			array(
				'name'   => 'main',
				'target' => array(
					'hash'       => self::CURRENT,
					'repository' => array(
						'full_name' => 'workspace/example',
						'uuid'      => '{repository-uuid}',
					),
				),
			)
		);
	}

	/** @param array<string, mixed> $body */
	private function response( int $status, array $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Isolated JSON fixture encoding.
			'body'     => json_encode( $body, JSON_THROW_ON_ERROR ),
		);
	}

	private function requestCount( string $code ): int {
		return 'gh' === $code
			? count( \RAN\GitHub\repository_resolver_http_requests() )
			: count( \RAN\RepositoryProvider\Bitbucket\bitbucket_credential_validation_http_requests() );
	}

	private function assertNoArchiveHooks( string $context ): void {
		self::assertSame( array(), \RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' ), $context );
		self::assertSame( array(), \RAN\RepositoryProvider\authenticated_archive_actions( AuthenticatedPreparedArchive::REDIRECT_HOOK ), $context );
	}

	private function resetHarness(): void {
		\RAN\RepositoryProvider\authenticated_archive_hooks_reset();
		\RAN\GitHub\repository_resolver_http_queue( array() );
		\RAN\RepositoryProvider\Bitbucket\bitbucket_repository_http_queue( array() );
	}
}
