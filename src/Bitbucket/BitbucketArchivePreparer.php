<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use InvalidArgumentException;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\AuthenticatedPreparedArchive;
use RAN\RepositoryProvider\GitReferenceSyntax;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\StaleDeployment;
use RuntimeException;

final readonly class BitbucketArchivePreparer {

	private const API_BASE      = 'https://api.bitbucket.org/2.0/repositories/';
	private const ARCHIVE_BASE  = 'https://bitbucket.org/';
	private const BRANCH_FIELDS = 'name,target.hash,target.repository.uuid,target.repository.full_name';
	private const TAG_FIELDS    = 'name,target.hash,target.repository.uuid,target.repository.full_name';
	private const COMMIT_FIELDS = 'hash,repository.uuid,repository.full_name';

	public function __construct(
		private BitbucketCredentialLoader $credentials,
		private BitbucketApiClient $api
	) {
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		$repository = $request->repository;

		try {
			$coordinates = BitbucketRepositoryCoordinates::from_full_name( $repository->locator );
		} catch ( InvalidArgumentException ) {
			throw new RuntimeException( 'Enter a valid Bitbucket repository in workspace/repository form.', 400 );
		}

		$workspace       = $coordinates->get_workspace();
		$repository_slug = $coordinates->get_repository_slug();
		$full_name       = $coordinates->get_full_name();
		$credential      = null;

		if ( $repository->private ) {
			$credential_id = $repository->credentialId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Certified Core RepositoryReference field; coordinated migration remains with Core #167.

			if ( null === $credential_id ) {
				throw new RuntimeException( 'A private Bitbucket repository requires an explicit credential.', 400 );
			}

			try {
				$credential = $this->credentials->load( $credential_id );
			} catch ( BitbucketCredentialException ) {
				throw new RuntimeException( 'The selected Bitbucket credential is unavailable or invalid.', 400 );
			}

			if ( 0 !== strcasecmp( $workspace, $credential->get_workspace() ) ) {
				throw new RuntimeException( 'The selected Bitbucket credential belongs to another workspace.', 400 );
			}
		}

		$commit = $this->immutable_commit(
			$workspace,
			$repository_slug,
			$full_name,
			$repository->providerRepositoryId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Certified Core RepositoryReference field; coordinated migration remains with Core #167.
			$request->ref,
			$request->expectedBranch, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Certified Core ArchiveRequest field; coordinated migration remains with Core #167.
			$credential
		);
		$url    = self::ARCHIVE_BASE
			. rawurlencode( $workspace )
			. '/'
			. rawurlencode( $repository_slug )
			. '/get/'
			. $commit
			. '.zip';

		$head_verifier   = null;
		$expected_branch = $request->expectedBranch; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Certified Core ArchiveRequest field; coordinated migration remains with Core #167.
		if ( null !== $expected_branch ) {
			$head_verifier = function () use ( $workspace, $repository_slug, $full_name, $repository, $expected_branch, $commit ): void {
				$verification_credential = null;
				if ( $repository->private ) {
					$credential_id = $repository->credentialId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Certified Core RepositoryReference field; coordinated migration remains with Core #167.
					if ( null === $credential_id ) {
						throw new RuntimeException( 'A private Bitbucket repository requires an explicit credential.', 400 );
					}

					try {
						$verification_credential = $this->credentials->load( $credential_id );
					} catch ( BitbucketCredentialException ) {
						throw new RuntimeException( 'The selected Bitbucket credential is unavailable or invalid.', 400 );
					}

					if ( 0 !== strcasecmp( $workspace, $verification_credential->get_workspace() ) ) {
						throw new RuntimeException( 'The selected Bitbucket credential belongs to another workspace.', 400 );
					}
				}

				$head = $this->resolve_branch(
					$workspace,
					$repository_slug,
					$full_name,
					$repository->providerRepositoryId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Certified Core RepositoryReference field; coordinated migration remains with Core #167.
					$expected_branch,
					$verification_credential
				);

				if ( ! hash_equals( $commit, $head ) ) {
					throw new StaleDeployment( 'The Bitbucket deployment event is stale because the configured branch has moved.', 409 );
				}
			};
		}

		$authorizer = null === $credential
			? null
			: static fn ( array $arguments ): array => $credential->authorize( $arguments );

		return new AuthenticatedPreparedArchive( $url, $commit, $authorizer, $head_verifier );
	}

	private function immutable_commit(
		string $workspace,
		string $repository_slug,
		string $full_name,
		?string $provider_repository_id,
		string $ref,
		?string $expected_branch,
		?BitbucketCredential $credential
	): string {
		if ( null !== $expected_branch && 1 !== preg_match( '/^[0-9a-f]{40}$/i', $ref ) ) {
			throw new RuntimeException( 'The Bitbucket deployment event does not contain a valid commit.', 400 );
		}

		if ( 1 === preg_match( '/^[0-9a-f]{40}$/i', $ref ) ) {
			$commit = strtolower( $ref );

			if ( null !== $expected_branch ) {
				$head = $this->resolve_branch(
					$workspace,
					$repository_slug,
					$full_name,
					$provider_repository_id,
					$expected_branch,
					$credential
				);

				if ( ! hash_equals( $commit, $head ) ) {
					throw new StaleDeployment( 'The Bitbucket deployment event is stale because the configured branch has moved.', 409 );
				}

				return $commit;
			}

			return $this->verify_commit(
				$workspace,
				$repository_slug,
				$full_name,
				$provider_repository_id,
				$commit,
				$credential
			);
		}

		try {
			return $this->resolve_branch(
				$workspace,
				$repository_slug,
				$full_name,
				$provider_repository_id,
				$ref,
				$credential
			);
		} catch ( RuntimeException $exception ) {
			if ( 404 !== $exception->getCode() ) {
				throw $exception;
			}
		}

		return $this->resolve_tag(
			$workspace,
			$repository_slug,
			$full_name,
			$provider_repository_id,
			$ref,
			$credential
		);
	}

	private function resolve_tag(
		string $workspace,
		string $repository_slug,
		string $full_name,
		?string $provider_repository_id,
		string $ref,
		?BitbucketCredential $credential
	): string {
		$tag = $this->validate_ref( $ref );
		$url = self::API_BASE
			. rawurlencode( $workspace )
			. '/'
			. rawurlencode( $repository_slug )
			. '/refs/tags/'
			. implode( '/', array_map( 'rawurlencode', explode( '/', $tag ) ) )
			. '?'
			. http_build_query( array( 'fields' => self::TAG_FIELDS ), '', '&', PHP_QUERY_RFC3986 );

		$data = $this->request_json( $url, $credential, 'tag' );

		return $this->resolved_named_ref( $data, $tag, $full_name, $provider_repository_id, 'tag' );
	}

	private function resolve_branch(
		string $workspace,
		string $repository_slug,
		string $full_name,
		?string $provider_repository_id,
		string $ref,
		?BitbucketCredential $credential
	): string {
		$branch = $this->validate_branch( $ref );
		$url    = self::API_BASE
			. rawurlencode( $workspace )
			. '/'
			. rawurlencode( $repository_slug )
			. '/refs/branches/'
			. implode( '/', array_map( 'rawurlencode', explode( '/', $branch ) ) )
			. '?'
			. http_build_query( array( 'fields' => self::BRANCH_FIELDS ), '', '&', PHP_QUERY_RFC3986 );

		$data = $this->request_json( $url, $credential, 'branch' );

		return $this->resolved_named_ref( $data, $branch, $full_name, $provider_repository_id, 'branch' );
	}

	/** @param array<string, mixed> $data */
	private function resolved_named_ref(
		array $data,
		string $name,
		string $full_name,
		?string $provider_repository_id,
		string $kind
	): string {
		$hash                = is_array( $data['target'] ?? null )
			? $data['target']['hash'] ?? null
			: null;
		$returned_repository = is_array( $data['target'] ?? null )
			&& is_array( $data['target']['repository'] ?? null )
			? $data['target']['repository']
			: null;

		if ( ! is_string( $data['name'] ?? null )
			|| ! hash_equals( $name, $data['name'] )
			|| ! is_string( $hash )
			|| 1 !== preg_match( '/^[0-9a-f]{40}$/i', $hash )
		) {
			$this->throw_invalid_response( $kind );
		}

		$this->assert_repository_identity(
			$returned_repository,
			$full_name,
			$provider_repository_id,
			$kind
		);

		return strtolower( $hash );
	}

	private function verify_commit(
		string $workspace,
		string $repository_slug,
		string $full_name,
		?string $provider_repository_id,
		string $commit,
		?BitbucketCredential $credential
	): string {
		$url = self::API_BASE
			. rawurlencode( $workspace )
			. '/'
			. rawurlencode( $repository_slug )
			. '/commit/'
			. $commit
			. '?'
			. http_build_query( array( 'fields' => self::COMMIT_FIELDS ), '', '&', PHP_QUERY_RFC3986 );

		$data = $this->request_json( $url, $credential, 'commit' );

		if ( ! is_string( $data['hash'] ?? null ) || ! hash_equals( $commit, $data['hash'] ) ) {
			throw new RuntimeException( 'Bitbucket returned an invalid commit-verification response.', 502 );
		}

		$this->assert_repository_identity(
			$data['repository'] ?? null,
			$full_name,
			$provider_repository_id,
			'commit'
		);

		return $commit;
	}

	/** @return array<string, mixed> */
	private function request_json(
		string $url,
		?BitbucketCredential $credential,
		string $kind
	): array {
		try {
			$response = $this->api->get( $url, $credential );
		} catch ( BitbucketApiException $exception ) {
			if ( BitbucketApiException::TRANSPORT_ERROR === $exception->get_reason() ) {
				throw new RuntimeException( 'Bitbucket could not resolve the requested revision.' );
			}

			$this->throw_invalid_response( $kind );
		}

		$this->assert_successful_response( $response, $kind );

		$data = json_decode( $response->get_body(), true, 512, JSON_BIGINT_AS_STRING );

		if ( ! is_array( $data ) ) {
			$this->throw_invalid_response( $kind );
		}

		return $data;
	}

	private function assert_successful_response( BitbucketApiResponse $response, string $kind ): void {
		$status = $response->get_status();
		$action = 'commit' === $kind ? 'verifying' : 'resolving';

		if ( 400 === $status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Kind is an internal fixed value.
			throw new RuntimeException( 'Bitbucket rejected the repository ' . $kind . '.', 400 );
		}

		if ( 401 === $status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Action and kind are internal fixed values.
			throw new RuntimeException( 'Bitbucket rejected the selected credential while ' . $action . ' the repository ' . $kind . '.', 401 );
		}

		if ( 403 === $status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Action and kind are internal fixed values.
			throw new RuntimeException( 'Bitbucket denied access while ' . $action . ' the repository ' . $kind . '.', 403 );
		}

		if ( 404 === $status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Kind is an internal fixed value.
			throw new RuntimeException( 'Bitbucket could not find that repository ' . $kind . ', or the selected credential cannot access it.', 404 );
		}

		if ( 410 === $status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Kind is an internal fixed value.
			throw new RuntimeException( 'The requested Bitbucket repository ' . $kind . ' is no longer available.', 410 );
		}

		if ( 429 === $status ) {
			throw new RuntimeException( 'Bitbucket rate-limited repository access. Try again later.', 429 );
		}

		if ( in_array( $status, array( 502, 503, 504 ), true ) ) {
			throw new RuntimeException( 'Bitbucket could not resolve the requested revision.' );
		}

		if ( $status < 200 || $status >= 300 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Kind is an internal fixed value.
			throw new RuntimeException( 'Bitbucket could not resolve the repository ' . $kind . '.', 502 );
		}
	}

	private function assert_repository_identity(
		mixed $repository,
		string $full_name,
		?string $provider_repository_id,
		string $kind
	): void {
		$returned_full_name = is_array( $repository ) ? $repository['full_name'] ?? null : null;
		$returned_uuid      = is_array( $repository ) ? $repository['uuid'] ?? null : null;

		if ( ! is_string( $returned_full_name )
			|| 0 !== strcasecmp( $full_name, $returned_full_name )
			|| ( null !== $provider_repository_id
				&& ( ! is_string( $returned_uuid ) || ! hash_equals( $provider_repository_id, $returned_uuid ) ) )
		) {
			$this->throw_invalid_response( $kind );
		}
	}

	private function throw_invalid_response( string $kind ): never {
		if ( 'commit' === $kind ) {
			throw new RuntimeException( 'Bitbucket returned an invalid commit-verification response.', 502 );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Kind is an internal fixed value.
		throw new RuntimeException( 'Bitbucket returned an invalid ' . $kind . '-resolution response.', 502 );
	}

	private function validate_branch( string $branch ): string {
		if ( ! GitReferenceSyntax::isValidNamedReference( $branch ) ) {
			throw new RuntimeException( 'Enter a valid Bitbucket repository branch.', 400 );
		}

		return $branch;
	}

	private function validate_ref( string $ref ): string {
		try {
			return $this->validate_branch( $ref );
		} catch ( RuntimeException ) {
			throw new RuntimeException( 'Enter a valid Bitbucket repository branch, tag or commit.', 400 );
		}
	}
}
