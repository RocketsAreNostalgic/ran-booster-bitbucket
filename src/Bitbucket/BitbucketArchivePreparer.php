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

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		$repository = $request->repository;

		try {
			$coordinates = BitbucketRepositoryCoordinates::fromFullName( $repository->locator );
		} catch ( InvalidArgumentException ) {
			throw new RuntimeException( 'Enter a valid Bitbucket repository in workspace/repository form.', 400 );
		}

		$workspace      = $coordinates->getWorkspace();
		$repositorySlug = $coordinates->getRepositorySlug();
		$fullName       = $coordinates->getFullName();
		$credential     = null;

		if ( $repository->private ) {
			$credentialId = $repository->credentialId;

			if ( null === $credentialId ) {
				throw new RuntimeException( 'A private Bitbucket repository requires an explicit credential.', 400 );
			}

			try {
				$credential = $this->credentials->load( $credentialId );
			} catch ( BitbucketCredentialException ) {
				throw new RuntimeException( 'The selected Bitbucket credential is unavailable or invalid.', 400 );
			}

			if ( 0 !== strcasecmp( $workspace, $credential->getWorkspace() ) ) {
				throw new RuntimeException( 'The selected Bitbucket credential belongs to another workspace.', 400 );
			}
		}

		$commit = $this->immutableCommit(
			$workspace,
			$repositorySlug,
			$fullName,
			$repository->providerRepositoryId,
			$request->ref,
			$request->expectedBranch,
			$credential
		);
		$url    = self::ARCHIVE_BASE
			. rawurlencode( $workspace )
			. '/'
			. rawurlencode( $repositorySlug )
			. '/get/'
			. $commit
			. '.zip';

		$headVerifier   = null;
		$expectedBranch = $request->expectedBranch;
		if ( null !== $expectedBranch ) {
			$headVerifier = function () use ( $workspace, $repositorySlug, $fullName, $repository, $expectedBranch, $commit ): void {
				$verificationCredential = null;
				if ( $repository->private ) {
					$credentialId = $repository->credentialId;
					if ( null === $credentialId ) {
						throw new RuntimeException( 'A private Bitbucket repository requires an explicit credential.', 400 );
					}

					try {
						$verificationCredential = $this->credentials->load( $credentialId );
					} catch ( BitbucketCredentialException ) {
						throw new RuntimeException( 'The selected Bitbucket credential is unavailable or invalid.', 400 );
					}

					if ( 0 !== strcasecmp( $workspace, $verificationCredential->getWorkspace() ) ) {
						throw new RuntimeException( 'The selected Bitbucket credential belongs to another workspace.', 400 );
					}
				}

				$head = $this->resolveBranch(
					$workspace,
					$repositorySlug,
					$fullName,
					$repository->providerRepositoryId,
					$expectedBranch,
					$verificationCredential
				);

				if ( ! hash_equals( $commit, $head ) ) {
					throw new StaleDeployment( 'The Bitbucket deployment event is stale because the configured branch has moved.', 409 );
				}
			};
		}

		$authorizer = null === $credential
			? null
			: static fn ( array $arguments ): array => $credential->authorize( $arguments );

		return new AuthenticatedPreparedArchive( $url, $commit, $authorizer, $headVerifier );
	}

	private function immutableCommit(
		string $workspace,
		string $repositorySlug,
		string $fullName,
		?string $providerRepositoryId,
		string $ref,
		?string $expectedBranch,
		?BitbucketCredential $credential
	): string {
		if ( null !== $expectedBranch && 1 !== preg_match( '/^[0-9a-f]{40}$/i', $ref ) ) {
			throw new RuntimeException( 'The Bitbucket deployment event does not contain a valid commit.', 400 );
		}

		if ( 1 === preg_match( '/^[0-9a-f]{40}$/i', $ref ) ) {
			$commit = strtolower( $ref );

			if ( null !== $expectedBranch ) {
				$head = $this->resolveBranch(
					$workspace,
					$repositorySlug,
					$fullName,
					$providerRepositoryId,
					$expectedBranch,
					$credential
				);

				if ( ! hash_equals( $commit, $head ) ) {
					throw new StaleDeployment( 'The Bitbucket deployment event is stale because the configured branch has moved.', 409 );
				}

				return $commit;
			}

			return $this->verifyCommit(
				$workspace,
				$repositorySlug,
				$fullName,
				$providerRepositoryId,
				$commit,
				$credential
			);
		}

		try {
			return $this->resolveBranch(
				$workspace,
				$repositorySlug,
				$fullName,
				$providerRepositoryId,
				$ref,
				$credential
			);
		} catch ( RuntimeException $exception ) {
			if ( 404 !== $exception->getCode() ) {
				throw $exception;
			}
		}

		return $this->resolveTag(
			$workspace,
			$repositorySlug,
			$fullName,
			$providerRepositoryId,
			$ref,
			$credential
		);
	}

	private function resolveTag(
		string $workspace,
		string $repositorySlug,
		string $fullName,
		?string $providerRepositoryId,
		string $ref,
		?BitbucketCredential $credential
	): string {
		$tag = $this->validateRef( $ref );
		$url = self::API_BASE
			. rawurlencode( $workspace )
			. '/'
			. rawurlencode( $repositorySlug )
			. '/refs/tags/'
			. implode( '/', array_map( 'rawurlencode', explode( '/', $tag ) ) )
			. '?'
			. http_build_query( array( 'fields' => self::TAG_FIELDS ), '', '&', PHP_QUERY_RFC3986 );

		$data = $this->requestJson( $url, $credential, 'tag' );

		return $this->resolvedNamedRef( $data, $tag, $fullName, $providerRepositoryId, 'tag' );
	}

	private function resolveBranch(
		string $workspace,
		string $repositorySlug,
		string $fullName,
		?string $providerRepositoryId,
		string $ref,
		?BitbucketCredential $credential
	): string {
		$branch = $this->validateBranch( $ref );
		$url    = self::API_BASE
			. rawurlencode( $workspace )
			. '/'
			. rawurlencode( $repositorySlug )
			. '/refs/branches/'
			. implode( '/', array_map( 'rawurlencode', explode( '/', $branch ) ) )
			. '?'
			. http_build_query( array( 'fields' => self::BRANCH_FIELDS ), '', '&', PHP_QUERY_RFC3986 );

		$data = $this->requestJson( $url, $credential, 'branch' );

		return $this->resolvedNamedRef( $data, $branch, $fullName, $providerRepositoryId, 'branch' );
	}

	/** @param array<string, mixed> $data */
	private function resolvedNamedRef(
		array $data,
		string $name,
		string $fullName,
		?string $providerRepositoryId,
		string $kind
	): string {
		$hash               = is_array( $data ) && is_array( $data['target'] ?? null )
			? $data['target']['hash'] ?? null
			: null;
		$returnedRepository = is_array( $data )
			&& is_array( $data['target'] ?? null )
			&& is_array( $data['target']['repository'] ?? null )
			? $data['target']['repository']
			: null;

		if ( ! is_array( $data )
			|| ! is_string( $data['name'] ?? null )
			|| ! hash_equals( $name, $data['name'] )
			|| ! is_string( $hash )
			|| 1 !== preg_match( '/^[0-9a-f]{40}$/i', $hash )
		) {
			$this->throwInvalidResponse( $kind );
		}

		$this->assertRepositoryIdentity(
			$returnedRepository,
			$fullName,
			$providerRepositoryId,
			$kind
		);

		return strtolower( $hash );
	}

	private function verifyCommit(
		string $workspace,
		string $repositorySlug,
		string $fullName,
		?string $providerRepositoryId,
		string $commit,
		?BitbucketCredential $credential
	): string {
		$url = self::API_BASE
			. rawurlencode( $workspace )
			. '/'
			. rawurlencode( $repositorySlug )
			. '/commit/'
			. $commit
			. '?'
			. http_build_query( array( 'fields' => self::COMMIT_FIELDS ), '', '&', PHP_QUERY_RFC3986 );

		$data = $this->requestJson( $url, $credential, 'commit' );

		if ( ! is_string( $data['hash'] ?? null ) || ! hash_equals( $commit, $data['hash'] ) ) {
			throw new RuntimeException( 'Bitbucket returned an invalid commit-verification response.', 502 );
		}

		$this->assertRepositoryIdentity(
			$data['repository'] ?? null,
			$fullName,
			$providerRepositoryId,
			'commit'
		);

		return $commit;
	}

	/** @return array<string, mixed> */
	private function requestJson(
		string $url,
		?BitbucketCredential $credential,
		string $kind
	): array {
		try {
			$response = $this->api->get( $url, $credential );
		} catch ( BitbucketApiException $exception ) {
			if ( BitbucketApiException::TRANSPORT_ERROR === $exception->getReason() ) {
				throw new RuntimeException( 'Bitbucket could not resolve the requested revision.' );
			}

			$this->throwInvalidResponse( $kind );
		}

		$this->assertSuccessfulResponse( $response, $kind );

		$data = json_decode( $response->getBody(), true, 512, JSON_BIGINT_AS_STRING );

		if ( ! is_array( $data ) ) {
			$this->throwInvalidResponse( $kind );
		}

		return $data;
	}

	private function assertSuccessfulResponse( BitbucketApiResponse $response, string $kind ): void {
		$status = $response->getStatus();
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

	private function assertRepositoryIdentity(
		mixed $repository,
		string $fullName,
		?string $providerRepositoryId,
		string $kind
	): void {
		$returnedFullName = is_array( $repository ) ? $repository['full_name'] ?? null : null;
		$returnedUuid     = is_array( $repository ) ? $repository['uuid'] ?? null : null;

		if ( ! is_string( $returnedFullName )
			|| 0 !== strcasecmp( $fullName, $returnedFullName )
			|| ( null !== $providerRepositoryId
				&& ( ! is_string( $returnedUuid ) || ! hash_equals( $providerRepositoryId, $returnedUuid ) ) )
		) {
			$this->throwInvalidResponse( $kind );
		}
	}

	private function throwInvalidResponse( string $kind ): never {
		if ( 'commit' === $kind ) {
			throw new RuntimeException( 'Bitbucket returned an invalid commit-verification response.', 502 );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Kind is an internal fixed value.
		throw new RuntimeException( 'Bitbucket returned an invalid ' . $kind . '-resolution response.', 502 );
	}

	private function validateBranch( string $branch ): string {
		if ( ! GitReferenceSyntax::isValidNamedReference( $branch ) ) {
			throw new RuntimeException( 'Enter a valid Bitbucket repository branch.', 400 );
		}

		return $branch;
	}

	private function validateRef( string $ref ): string {
		try {
			return $this->validateBranch( $ref );
		} catch ( RuntimeException ) {
			throw new RuntimeException( 'Enter a valid Bitbucket repository branch, tag or commit.', 400 );
		}
	}
}
