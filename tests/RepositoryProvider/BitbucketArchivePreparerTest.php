<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once __DIR__ . '/BitbucketCredentialValidatorWordPressFunctions.php';
require_once __DIR__ . '/BitbucketRepositoryBrowserSecretsStub.php';
require_once __DIR__ . '/AuthenticatedPreparedArchiveWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\AuthenticatedPreparedArchive;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\Booster\Bitbucket\BitbucketApiClient;
use RAN\Booster\Bitbucket\BitbucketArchivePreparer;
use RAN\Booster\Bitbucket\BitbucketCredentialLoader;
use RAN\Booster\Bitbucket\BitbucketCredentialValidationTransportError;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryReference;
use RuntimeException;

final class BitbucketArchivePreparerTest extends TestCase {

	private const EMAIL           = 'deploy@example.test';
	private const TOKEN           = 'bitbucket-archive-token-canary';
	private const RESPONSE_CANARY = 'bitbucket-archive-response-canary';
	private const COMMIT          = '0123456789abcdef0123456789abcdef01234567';
	private const OTHER_COMMIT    = '89abcdef0123456789abcdef0123456789abcdef';
	private const REPOSITORY_UUID = '{repository-uuid}';

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the PHPUnit lifecycle override signature.
	protected function setUp(): void {
		parent::setUp();

		$this->reset_harness();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Preserve the PHPUnit lifecycle override signature.
	protected function tearDown(): void {
		$this->reset_harness();

		parent::tearDown();
	}

	public function test_public_immutable_commit_is_verified_anonymously_without_credential_or_hooks(): void {
		$this->queue(
			array(
				$this->response( 200, $this->commit_body( self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
			)
		);
		$secrets  = $this->secrets( array( 'profile' => $this->credential() ) );
		$archive  = $this->preparer( $secrets )->prepare_archive(
			$this->request( strtoupper( self::COMMIT ), false, 'profile' )
		);
		$requests = $this->requests();

		self::assertSame(
			'https://bitbucket.org/acme/example/get/' . self::COMMIT . '.zip',
			$archive->get_url()
		);
		self::assertCount( 1, $requests );
		self::assertSame(
			'https://api.bitbucket.org/2.0/repositories/acme/example/commit/' . self::COMMIT . '?fields=hash%2Crepository.uuid%2Crepository.full_name',
			$requests[0]['url']
		);
		self::assertArrayNotHasKey( 'Authorization', $requests[0]['arguments']['headers'] );
		self::assertSame( array(), $secrets->material_lookups );
		$this->assert_no_archive_hooks();

		$archive->cleanup();
		$archive->cleanup();
		$this->assert_no_archive_hooks();
	}

	public function test_public_slash_branch_resolves_an_immutable_repository_bound_commit_without_auth(): void {
		$this->queue(
			array(
				$this->response(
					200,
					$this->branch_body( 'feature/candidate', self::COMMIT, 'acme/example', self::REPOSITORY_UUID )
				),
			)
		);

		$archive  = $this->preparer()->prepare_archive( request: $this->request( 'feature/candidate', false ) );
		$requests = $this->requests();

		self::assertSame(
			'https://api.bitbucket.org/2.0/repositories/acme/example/refs/branches/feature/candidate?fields=name%2Ctarget.hash%2Ctarget.repository.uuid%2Ctarget.repository.full_name',
			$requests[0]['url']
		);
		self::assertArrayNotHasKey( 'Authorization', $requests[0]['arguments']['headers'] );
		self::assertSame( 'https://bitbucket.org/acme/example/get/' . self::COMMIT . '.zip', $archive->get_url() );
		self::assertSame( self::COMMIT, $archive->get_resolved_ref() );
		$this->assert_no_archive_hooks();
	}

	public function test_manual_tag_falls_back_from_branch_and_resolves_an_immutable_commit(): void {
		$this->queue(
			array(
				$this->response( 404, $this->error_body() ),
				$this->response( 200, $this->branch_body( 'v1.2.3', self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
			)
		);

		$archive  = $this->preparer()->prepare_archive( $this->request( 'v1.2.3', false ) );
		$requests = $this->requests();

		self::assertCount( 2, $requests );
		self::assertStringContainsString( '/refs/branches/v1.2.3?', $requests[0]['url'] );
		self::assertStringContainsString( '/refs/tags/v1.2.3?', $requests[1]['url'] );
		self::assertSame( self::COMMIT, $archive->get_resolved_ref() );
		self::assertStringEndsWith( '/' . self::COMMIT . '.zip', $archive->get_url() );
	}

	public function test_private_branch_resolution_uses_exact_basic_auth_then_prepares_immutable_archive_auth(): void {
		$this->queue(
			array(
				$this->response( 200, $this->branch_body( 'main', strtoupper( self::COMMIT ), 'ACME/EXAMPLE', self::REPOSITORY_UUID ) ),
			)
		);
		$secrets = $this->secrets( array( 'profile' => $this->credential() ) );

		$archive  = $this->preparer( $secrets )->prepare_archive( $this->request( 'main', true, 'profile' ) );
		$requests = $this->requests();

		self::assertSame(
			'https://api.bitbucket.org/2.0/repositories/acme/example/refs/branches/main?fields=name%2Ctarget.hash%2Ctarget.repository.uuid%2Ctarget.repository.full_name',
			$requests[0]['url']
		);
		self::assertSame( $this->basic_authorization(), $requests[0]['arguments']['headers']['Authorization'] );
		self::assertSame( 'https://bitbucket.org/acme/example/get/' . self::COMMIT . '.zip', $archive->get_url() );
		self::assertStringNotContainsString( self::TOKEN, $archive->get_url() );
		self::assertStringNotContainsString( self::EMAIL, $archive->get_url() );
		self::assertCount( 1, $this->archive_filters() );
		self::assertCount( 1, $this->archive_actions() );
	}

	public function test_manual_full_commit_without_expected_branch_uses_commit_verification(): void {
		$this->queue(
			array(
				$this->response( 200, $this->commit_body( self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
			)
		);
		$archive  = $this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
			->prepare_archive( $this->request( strtoupper( self::COMMIT ), true, 'profile' ) );
		$requests = $this->requests();

		self::assertCount( 1, $requests );
		self::assertSame(
			'https://api.bitbucket.org/2.0/repositories/acme/example/commit/' . self::COMMIT . '?fields=hash%2Crepository.uuid%2Crepository.full_name',
			$requests[0]['url']
		);
		self::assertSame( $this->basic_authorization(), $requests[0]['arguments']['headers']['Authorization'] );
		self::assertSame( 'https://bitbucket.org/acme/example/get/' . self::COMMIT . '.zip', $archive->get_url() );
		self::assertCount( 1, $this->archive_filters() );
		self::assertCount( 1, $this->archive_actions() );
	}

	public function test_webhook_commit_checks_the_configured_branch_head_before_preparing_archive_authentication(): void {
		$branch = 'release/candidate';
		$this->queue(
			array(
				$this->response(
					200,
					$this->branch_body( $branch, strtoupper( self::COMMIT ), 'ACME/EXAMPLE', self::REPOSITORY_UUID )
				),
			)
		);
		$archive  = $this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
			->prepare_archive( $this->request( strtoupper( self::COMMIT ), true, 'profile', 'acme/example', $branch ) );
		$requests = $this->requests();

		self::assertCount( 1, $requests );
		self::assertSame(
			'https://api.bitbucket.org/2.0/repositories/acme/example/refs/branches/release/candidate?fields=name%2Ctarget.hash%2Ctarget.repository.uuid%2Ctarget.repository.full_name',
			$requests[0]['url']
		);
		self::assertSame( $this->basic_authorization(), $requests[0]['arguments']['headers']['Authorization'] );
		self::assertSame( 'https://bitbucket.org/acme/example/get/' . self::COMMIT . '.zip', $archive->get_url() );
		self::assertCount( 1, $this->archive_filters() );
		self::assertCount( 1, $this->archive_actions() );
	}

	public function test_public_webhook_commit_checks_the_configured_branch_anonymously(): void {
		$this->queue(
			array(
				$this->response(
					200,
					$this->branch_body( 'main', self::COMMIT, 'acme/example', self::REPOSITORY_UUID )
				),
			)
		);
		$archive  = $this->preparer()->prepare_archive(
			$this->request( self::COMMIT, false, null, 'acme/example', 'main' )
		);
		$requests = $this->requests();

		self::assertCount( 1, $requests );
		self::assertSame(
			'https://api.bitbucket.org/2.0/repositories/acme/example/refs/branches/main?fields=name%2Ctarget.hash%2Ctarget.repository.uuid%2Ctarget.repository.full_name',
			$requests[0]['url']
		);
		self::assertArrayNotHasKey( 'Authorization', $requests[0]['arguments']['headers'] );
		self::assertSame( 'https://bitbucket.org/acme/example/get/' . self::COMMIT . '.zip', $archive->get_url() );
		$this->assert_no_archive_hooks();
	}

	public function test_automatic_archive_rechecks_the_branch_immediately_before_mutation(): void {
		$this->queue(
			array(
				$this->response( 200, $this->branch_body( 'main', self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
				$this->response( 200, $this->branch_body( 'main', self::OTHER_COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
			)
		);
		$archive = $this->preparer()->prepare_archive(
			$this->request( self::COMMIT, false, null, 'acme/example', 'main' )
		);

		try {
			$archive->verify_current_head();
			self::fail( 'The second Bitbucket head check must reject a branch that moved before mutation.' );
		} catch ( \RAN\RepositoryProvider\StaleDeployment $exception ) {
			self::assertSame( 409, $exception->getCode() );
		}

		self::assertCount( 2, $this->requests() );
	}

	public function test_expected_branch_rejects_non_commit_ref_before_http_or_archive_authentication(): void {
		try {
			$this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
				->prepare_archive( $this->request( 'main', true, 'profile', 'acme/example', 'main' ) );
			self::fail( 'An expected branch must be paired with an immutable commit.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 400, $exception->getCode() );
			self::assertSame( 'The Bitbucket deployment event does not contain a valid commit.', $exception->getMessage() );
		}

		self::assertSame( array(), $this->requests() );
		$this->assert_no_archive_hooks();
	}

	public function test_stale_or_mismatched_webhook_branch_responses_fail_before_archive_authentication(): void {
		$fixtures = array(
			'stale head'          => array(
				$this->branch_body( 'main', self::OTHER_COMMIT, 'acme/example', self::REPOSITORY_UUID ),
				409,
				'The Bitbucket deployment event is stale because the configured branch has moved.',
			),
			'branch mismatch'     => array(
				$this->branch_body( 'other', self::COMMIT, 'acme/example', self::REPOSITORY_UUID ),
				502,
				null,
			),
			'repository mismatch' => array(
				$this->branch_body( 'main', self::COMMIT, 'other/example', self::REPOSITORY_UUID ),
				502,
				null,
			),
			'uuid mismatch'       => array(
				$this->branch_body( 'main', self::COMMIT, 'acme/example', '{other-uuid}' ),
				502,
				null,
			),
		);

		foreach ( $fixtures as $name => [$body, $code, $expected_message] ) {
			$context = (string) $name;
			$this->reset_harness();
			$this->queue( array( $this->response( 200, $body ) ) );

			try {
				$this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
					->prepare_archive( $this->request( self::COMMIT, true, 'profile', 'acme/example', 'main' ) );
				self::fail( 'Expected the webhook branch guard to reject: ' . $context );
			} catch ( RuntimeException $exception ) {
				self::assertSame( $code, $exception->getCode(), $context );
				if ( null !== $expected_message ) {
					self::assertSame( $expected_message, $exception->getMessage(), $context );
				}
				$this->assert_safe_failure( $exception, $context );
			}

			self::assertCount( 1, $this->requests(), $context );
			self::assertSame(
				'https://api.bitbucket.org/2.0/repositories/acme/example/refs/branches/main?fields=name%2Ctarget.hash%2Ctarget.repository.uuid%2Ctarget.repository.full_name',
				$this->requests()[0]['url'],
				$context
			);
			self::assertSame( $this->basic_authorization(), $this->requests()[0]['arguments']['headers']['Authorization'], $context );
			$this->assert_no_archive_hooks( $context );
		}
	}

	public function test_invalid_provider_repository_ref_credential_and_workspace_fail_before_http_or_hooks(): void {
		$fixtures = array(
			'repository traversal' => fn (): ArchiveRequest => $this->request( self::COMMIT, false, null, 'acme/../example' ),
			'ref traversal'        => fn (): ArchiveRequest => $this->request( '../main', false ),
			'ref repeated slash'   => fn (): ArchiveRequest => $this->request( 'feature//candidate', false ),
			'ref control'          => fn (): ArchiveRequest => $this->request( "main\n", false ),
			'missing credential'   => fn (): ArchiveRequest => $this->request( self::COMMIT, true, 'missing' ),
			'implicit credential'  => fn (): ArchiveRequest => $this->request( self::COMMIT, true ),
		);

		foreach ( $fixtures as $name => $request_factory ) {
			$this->reset_harness();

			try {
				$this->preparer()->prepare_archive( $request_factory() );
				self::fail( 'Expected invalid archive input to fail: ' . $name );
			} catch ( \Throwable $exception ) {
				$this->assert_safe_failure( $exception, $name );
			}

			self::assertSame( array(), $this->requests(), $name );
			$this->assert_no_archive_hooks( $name );
		}

		$this->reset_harness();
		$secrets = $this->secrets( array( 'other' => $this->credential( 'other' ) ) );

		try {
			$this->preparer( $secrets )->prepare_archive( $this->request( self::COMMIT, true, 'other' ) );
			self::fail( 'Expected a credential for another workspace to fail.' );
		} catch ( RuntimeException $exception ) {
			$this->assert_safe_failure( $exception, 'workspace mismatch' );
		}

		self::assertSame( array(), $this->requests() );
		$this->assert_no_archive_hooks();
	}

	public function test_branch_status_transport_and_malformed_responses_fail_safely_without_archive_hooks(): void {
		$fixtures = array(
			'transport'           => array( new BitbucketCredentialValidationTransportError(), 0, true ),
			'blocked transport'   => array( new BitbucketCredentialValidationTransportError( 'http_request_not_executed' ), 502, false ),
			'local policy error'  => array( new BitbucketCredentialValidationTransportError( 'local_policy_canary' ), 502, false ),
			'no transport'        => array( new BitbucketCredentialValidationTransportError( 'http_failure' ), 502, false ),
			'400'                 => array( $this->response( 400, $this->error_body() ), 400, false ),
			'401'                 => array( $this->response( 401, $this->error_body() ), 401, false ),
			'403'                 => array( $this->response( 403, $this->error_body() ), 403, false ),
			'404'                 => array( $this->response( 404, $this->error_body() ), 404, false ),
			'410'                 => array( $this->response( 410, $this->error_body() ), 410, false ),
			'429'                 => array( $this->response( 429, $this->error_body() ), 429, false ),
			'503'                 => array( $this->response( 503, $this->error_body() ), 0, true ),
			'502'                 => array( $this->response( 502, $this->error_body() ), 0, true ),
			'504'                 => array( $this->response( 504, $this->error_body() ), 0, true ),
			'500'                 => array( $this->response( 500, $this->error_body() ), 502, false ),
			'501'                 => array( $this->response( 501, $this->error_body() ), 502, false ),
			'505'                 => array( $this->response( 505, $this->error_body() ), 502, false ),
			'not json'            => array( $this->response( 200, self::RESPONSE_CANARY ), 502, false ),
			'null json'           => array( $this->response( 200, 'null' ), 502, false ),
			'scalar json'         => array( $this->response( 200, '42' ), 502, false ),
			'scalar target'       => array( $this->response( 200, '{"name":"main","target":42}' ), 502, false ),
			'scalar repository'   => array(
				$this->response(
					200,
					$this->json(
						array(
							'name'   => 'main',
							'target' => array(
								'hash'       => self::COMMIT,
								'repository' => 42,
							),
						)
					)
				),
				502,
				false,
			),
			'missing repository'  => array(
				$this->response(
					200,
					$this->json(
						array(
							'name'   => 'main',
							'target' => array( 'hash' => self::COMMIT ),
						)
					)
				),
				502,
				false,
			),
			'missing target'      => array( $this->response( 200, '{"name":"main"}' ), 502, false ),
			'branch mismatch'     => array( $this->response( 200, $this->branch_body( 'other', self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ), 502, false ),
			'invalid hash'        => array( $this->response( 200, $this->branch_body( 'main', 'not-a-hash', 'acme/example', self::REPOSITORY_UUID ) ), 502, false ),
			'repository mismatch' => array( $this->response( 200, $this->branch_body( 'main', self::COMMIT, 'other/example', self::REPOSITORY_UUID ) ), 502, false ),
			'uuid mismatch'       => array( $this->response( 200, $this->branch_body( 'main', self::COMMIT, 'acme/example', '{other-uuid}' ) ), 502, false ),
		);

		foreach ( $fixtures as $name => [$response, $code, $retryable] ) {
			$context = (string) $name;
			$this->reset_harness();
			$this->queue( '404' === $context ? array( $response, $response ) : array( $response ) );

			try {
				$this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
					->prepare_archive( $this->request( 'main', true, 'profile' ) );
				self::fail( 'Expected branch resolution failure: ' . $context );
			} catch ( RuntimeException $exception ) {
				self::assertSame( $retryable ? 0 : $code, $exception->getCode(), $context );
				$this->assert_safe_failure( $exception, $context );
			}

			self::assertCount( '404' === $context ? 2 : 1, $this->requests(), $context );
			self::assertStringNotContainsString( self::TOKEN, $this->requests()[0]['url'], $context );
			self::assertStringNotContainsString( self::EMAIL, $this->requests()[0]['url'], $context );
			$this->assert_no_archive_hooks( $context );
		}
	}

	public function test_direct_commit_status_transport_and_identity_failures_are_safe_and_hook_free(): void {
		$fixtures = array(
			'transport'           => array( new BitbucketCredentialValidationTransportError(), 0, true ),
			'blocked transport'   => array( new BitbucketCredentialValidationTransportError( 'http_request_not_executed' ), 502, false ),
			'local policy error'  => array( new BitbucketCredentialValidationTransportError( 'local_policy_canary' ), 502, false ),
			'no transport'        => array( new BitbucketCredentialValidationTransportError( 'http_failure' ), 502, false ),
			'400'                 => array( $this->response( 400, $this->error_body() ), 400, false ),
			'401'                 => array( $this->response( 401, $this->error_body() ), 401, false ),
			'403'                 => array( $this->response( 403, $this->error_body() ), 403, false ),
			'404'                 => array( $this->response( 404, $this->error_body() ), 404, false ),
			'410'                 => array( $this->response( 410, $this->error_body() ), 410, false ),
			'429'                 => array( $this->response( 429, $this->error_body() ), 429, false ),
			'503'                 => array( $this->response( 503, $this->error_body() ), 0, true ),
			'502'                 => array( $this->response( 502, $this->error_body() ), 0, true ),
			'504'                 => array( $this->response( 504, $this->error_body() ), 0, true ),
			'500'                 => array( $this->response( 500, $this->error_body() ), 502, false ),
			'501'                 => array( $this->response( 501, $this->error_body() ), 502, false ),
			'505'                 => array( $this->response( 505, $this->error_body() ), 502, false ),
			'not json'            => array( $this->response( 200, self::RESPONSE_CANARY ), 502, false ),
			'missing repository'  => array( $this->response( 200, $this->json( array( 'hash' => self::COMMIT ) ) ), 502, false ),
			'hash mismatch'       => array( $this->response( 200, $this->commit_body( self::OTHER_COMMIT, 'acme/example', self::REPOSITORY_UUID ) ), 502, false ),
			'repository mismatch' => array( $this->response( 200, $this->commit_body( self::COMMIT, 'other/example', self::REPOSITORY_UUID ) ), 502, false ),
			'uuid mismatch'       => array( $this->response( 200, $this->commit_body( self::COMMIT, 'acme/example', '{other-uuid}' ) ), 502, false ),
		);

		foreach ( $fixtures as $name => [$response, $code, $retryable] ) {
			$context = (string) $name;
			$this->reset_harness();
			$this->queue( array( $response ) );

			try {
				$this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
					->prepare_archive( $this->request( self::COMMIT, true, 'profile' ) );
				self::fail( 'Expected direct commit verification failure: ' . $context );
			} catch ( RuntimeException $exception ) {
				self::assertSame( $retryable ? 0 : $code, $exception->getCode(), $context );
				$this->assert_safe_failure( $exception, $context );
			}

			self::assertCount( 1, $this->requests(), $context );
			self::assertSame(
				'https://api.bitbucket.org/2.0/repositories/acme/example/commit/' . self::COMMIT . '?fields=hash%2Crepository.uuid%2Crepository.full_name',
				$this->requests()[0]['url'],
				$context
			);
			self::assertSame( $this->basic_authorization(), $this->requests()[0]['arguments']['headers']['Authorization'], $context );
			self::assertStringNotContainsString( self::TOKEN, $this->requests()[0]['url'], $context );
			self::assertStringNotContainsString( self::EMAIL, $this->requests()[0]['url'], $context );
			$this->assert_no_archive_hooks( $context );
		}
	}

	public function test_private_authentication_is_one_shot_and_bound_to_the_exact_immutable_archive(): void {
		$archive  = $this->private_immutable_archive();
		$callback = $this->archive_filters()[0]['callback'];
		$url      = $archive->get_url();
		$hostile  = array(
			'https://example.test/archive.zip',
			'https://bitbucket.org.evil.test/acme/example/get/' . self::COMMIT . '.zip',
			'https://bitbucket.org/acme/other/get/' . self::COMMIT . '.zip',
			'https://bitbucket.org/acme/example/get/' . self::OTHER_COMMIT . '.zip',
			'http://bitbucket.org/acme/example/get/' . self::COMMIT . '.zip',
			$url . '?download=1',
			$url . '#fragment',
		);

		foreach ( $hostile as $candidate ) {
			$arguments = $callback( array( 'headers' => array( 'Existing' => 'value' ) ), $candidate );

			self::assertSame( array( 'Existing' => 'value' ), $arguments['headers'], $candidate );
			self::assertCount( 1, $this->archive_filters(), $candidate );
		}

		$arguments = $callback( array( 'headers' => array( 'Existing' => 'value' ) ), $url );

		self::assertSame( 'value', $arguments['headers']['Existing'] );
		self::assertSame( $this->basic_authorization(), $arguments['headers']['Authorization'] );
		self::assertSame( array(), $this->archive_filters() );
		self::assertCount( 1, $this->archive_actions() );

		try {
			$callback( array( 'headers' => array() ), $url );
			self::fail( 'Expected consumed archive authentication to remain unavailable.' );
		} catch ( RuntimeException $exception ) {
			$this->assert_safe_failure( $exception );
		}
	}

	public function test_redirect_scrubber_only_removes_auth_inherited_from_the_exact_archive_origin(): void {
		$archive           = $this->private_immutable_archive();
		$request_callback  = $this->archive_filters()[0]['callback'];
		$redirect_callback = $this->archive_actions()[0]['callback'];
		$url               = $archive->get_url();
		$arguments         = $request_callback( array( 'headers' => array() ), $url );
		$location          = 'https://bbuseruploads.example.test/signed/archive.zip';
		$unrelated_headers = $arguments['headers'];
		$archive_headers   = array(
			'authorization' => $arguments['headers']['Authorization'],
			'Existing'      => 'value',
		);

		call_user_func_array(
			$redirect_callback,
			array( &$location, &$unrelated_headers, null, array(), (object) array( 'url' => 'https://example.test/' ) )
		);
		self::assertArrayHasKey( 'Authorization', $unrelated_headers );

		call_user_func_array(
			$redirect_callback,
			array( &$location, &$archive_headers, null, array(), (object) array( 'url' => $url ) )
		);
		self::assertArrayNotHasKey( 'authorization', $archive_headers );
		self::assertSame( 'value', $archive_headers['Existing'] );
		self::assertStringNotContainsString( self::TOKEN, $location );

		$archive->cleanup();
		$this->assert_no_archive_hooks();
	}

	public function test_cleanup_is_idempotent_before_and_after_authentication(): void {
		$cancelled = $this->private_immutable_archive();
		$callback  = $this->archive_filters()[0]['callback'];

		$cancelled->cleanup();
		$cancelled->cleanup();
		$this->assert_no_archive_hooks();

		try {
			$callback( array( 'headers' => array() ), $cancelled->get_url() );
			self::fail( 'Expected cleaned archive authentication to remain unavailable.' );
		} catch ( RuntimeException $exception ) {
			$this->assert_safe_failure( $exception );
		}

		$consumed = $this->private_immutable_archive();
		$this->archive_filters()[0]['callback']( array( 'headers' => array() ), $consumed->get_url() );
		$consumed->cleanup();
		$consumed->cleanup();
		$this->assert_no_archive_hooks();
	}

	public function test_private_archive_exposes_only_its_immutable_resolved_ref(): void {
		$archive = $this->private_immutable_archive();

		self::assertSame( self::COMMIT, $archive->get_resolved_ref() );
		self::assertStringNotContainsString( self::TOKEN, $archive->get_url() );
		self::assertStringNotContainsString( self::EMAIL, $archive->get_url() );
		$archive->cleanup();

		$this->assert_no_archive_hooks();
	}

	private function private_immutable_archive(): AuthenticatedPreparedArchive {
		$this->queue(
			array(
				$this->response( 200, $this->commit_body( self::COMMIT, 'acme/example', self::REPOSITORY_UUID ) ),
			)
		);
		$archive = $this->preparer( $this->secrets( array( 'profile' => $this->credential() ) ) )
			->prepare_archive( $this->request( self::COMMIT, true, 'profile' ) );

		self::assertInstanceOf( AuthenticatedPreparedArchive::class, $archive );

		return $archive;
	}

	private function preparer( ?BitbucketRepositoryBrowserSecretsStub $secrets = null ): BitbucketArchivePreparer {
		return new BitbucketArchivePreparer(
			new BitbucketCredentialLoader( new BitbucketProviderCredentialStore( $secrets ?? $this->secrets() ) ),
			new BitbucketApiClient()
		);
	}

	private function request(
		string $ref,
		bool $is_private,
		?string $credential_id = null,
		string $full_name = 'acme/example',
		?string $expected_branch = null
	): ArchiveRequest {
		return new ArchiveRequest(
			new RepositoryReference( $full_name, self::REPOSITORY_UUID, $is_private, $credential_id ),
			$ref,
			$expected_branch
		);
	}

	/** @param array<string, array<string, mixed>> $materials */
	private function secrets( array $materials = array() ): BitbucketRepositoryBrowserSecretsStub {
		return new BitbucketRepositoryBrowserSecretsStub( $materials );
	}

	/** @return array<string, mixed> */
	private function credential( string $workspace = 'acme' ): array {
		return array(
			'id'            => 'profile',
			'provider'      => 'bb',
			'label'         => 'Archive deployment',
			'kind'          => 'api-token',
			'configuration' => array(
				'workspace' => $workspace,
				'email'     => self::EMAIL,
			),
			'secret'        => self::TOKEN,
			'source'        => 'file',
			'immutable'     => false,
			'configured'    => true,
		);
	}

	private function basic_authorization(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Assert Bitbucket's required Basic wire value.
		return 'Basic ' . base64_encode( self::EMAIL . ':' . self::TOKEN );
	}

	private function branch_body( string $name, string $hash, string $full_name, string $uuid ): string {
		return $this->json(
			array(
				'name'   => $name,
				'target' => array(
					'hash'       => $hash,
					'repository' => array(
						'full_name' => $full_name,
						'uuid'      => $uuid,
					),
				),
			)
		);
	}

	private function commit_body( string $hash, string $full_name, string $uuid ): string {
		return $this->json(
			array(
				'hash'       => $hash,
				'repository' => array(
					'full_name' => $full_name,
					'uuid'      => $uuid,
				),
			)
		);
	}

	private function error_body(): string {
		return '{"error":"' . self::RESPONSE_CANARY . '"}';
	}

	private function json( mixed $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Isolated JSON fixture encoding outside WordPress runtime.
		$json = json_encode( $value );

		self::assertIsString( $json );

		return $json;
	}

	/** @return array<string, mixed> */
	private function response( int $status, string $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		);
	}

	/** @param list<mixed> $responses */
	private function queue( array $responses ): void {
		\RAN\Booster\Bitbucket\bitbucket_repository_http_queue( $responses );
	}

	/** @return list<array{url: string, arguments: array<string, mixed>}> */
	private function requests(): array {
		return \RAN\Booster\Bitbucket\bitbucket_credential_validation_http_requests();
	}

	/** @return list<array{callback: callable, priority: int, accepted_args: int}> */
	private function archive_filters(): array {
		return \RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' );
	}

	/** @return list<array{callback: callable, priority: int, accepted_args: int}> */
	private function archive_actions(): array {
		return \RAN\RepositoryProvider\authenticated_archive_actions( AuthenticatedPreparedArchive::REDIRECT_HOOK );
	}

	private function assert_no_archive_hooks( string $context = '' ): void {
		self::assertSame( array(), $this->archive_filters(), $context );
		self::assertSame( array(), $this->archive_actions(), $context );
	}

	private function assert_safe_failure( \Throwable $exception, string $context = '' ): void {
		self::assertNotSame( '', trim( $exception->getMessage() ), $context );
		self::assertStringNotContainsString( self::TOKEN, $exception->getMessage(), $context );
		self::assertStringNotContainsString( self::EMAIL, $exception->getMessage(), $context );
		self::assertStringNotContainsString( self::RESPONSE_CANARY, $exception->getMessage(), $context );
	}

	private function reset_harness(): void {
		\RAN\RepositoryProvider\authenticated_archive_hooks_reset();
		$this->queue( array() );
	}
}
