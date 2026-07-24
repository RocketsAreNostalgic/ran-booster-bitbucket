<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use InvalidArgumentException;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryBrowseMode;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RuntimeException;

final readonly class BitbucketRepositoryBrowser {

	private const API_BASE      = 'https://api.bitbucket.org/2.0/repositories/';
	private const PER_PAGE      = 100;
	private const LIST_FIELDS   = 'values.uuid,values.full_name,values.is_private,values.mainbranch.name,next';
	private const LOOKUP_FIELDS = 'uuid,full_name,is_private,mainbranch.name';

	public function __construct(
		private BitbucketCredentialLoader $credentials,
		private BitbucketApiClient $api
	) {
	}

	public function browse( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		if ( RepositoryBrowseMode::PUBLIC_OWNER === $request->getMode() ) {
			$workspace  = $this->validateWorkspace( (string) $request->getOwner() );
			$credential = null;

			if ( null !== $request->getCredentialId() ) {
				try {
					$credential = $this->credentials->load( $request->getCredentialId() );
				} catch ( BitbucketCredentialException ) {
					throw new RuntimeException( 'The selected Bitbucket credential is unavailable or invalid.', 400 );
				}
			}

			return $this->listRepositories(
				$workspace,
				$credential,
				null,
				true,
				$request
			);
		}

		$credentialId = (string) $request->getCredentialId();
		try {
			$credential = $this->credentials->load( $credentialId );
		} catch ( BitbucketCredentialException ) {
			throw new RuntimeException( 'The selected Bitbucket credential is unavailable or invalid.', 400 );
		}

		return $this->listRepositories(
			$credential->getWorkspace(),
			$credential,
			$credentialId,
			false,
			$request
		);
	}

	public function repository(
		string $fullName,
		?string $credentialId = null,
		float|int $timeout = 15,
		int $responseSize = 262144,
		bool $publicOnly = false
	): RepositoryDescriptor {
		try {
			$coordinates = BitbucketRepositoryCoordinates::fromFullName( $fullName );
		} catch ( InvalidArgumentException ) {
			throw new RuntimeException( 'Enter a valid Bitbucket repository in workspace/repository form.', 400 );
		}

		$workspace            = $coordinates->getWorkspace();
		$repositorySlug       = $coordinates->getRepositorySlug();
		$credential           = null;
		$resolvedCredentialId = null;

		if ( null !== $credentialId ) {
			try {
				$credential = $this->credentials->load( $credentialId );
			} catch ( BitbucketCredentialException ) {
				throw new RuntimeException( 'The selected Bitbucket credential is unavailable or invalid.', 400 );
			}

			if ( ! $publicOnly && 0 !== strcasecmp( $workspace, $credential->getWorkspace() ) ) {
				throw new RuntimeException( 'The selected Bitbucket credential belongs to another workspace.', 400 );
			}

			$resolvedCredentialId = trim( $credentialId );
		}

		$url = self::API_BASE
			. rawurlencode( $workspace )
			. '/'
			. rawurlencode( $repositorySlug )
			. '?'
			. http_build_query( array( 'fields' => self::LOOKUP_FIELDS ), '', '&', PHP_QUERY_RFC3986 );

		$response = $this->request(
			$url,
			$credential,
			'Bitbucket could not find that repository, or the selected credential cannot access it.',
			$timeout,
			$responseSize
		);
		$item     = json_decode( $response->getBody(), true, 512, JSON_BIGINT_AS_STRING );

		$repository = $this->descriptorFromItem( $item, $resolvedCredentialId, $workspace, true );

		if ( null === $repository || ! $coordinates->matchesFullName( $repository->locator ) ) {
			throw new RuntimeException( 'Bitbucket returned an invalid repository response.', 502 );
		}

		if ( $publicOnly && $repository->private ) {
			throw new RuntimeException( 'The selected Bitbucket repository is not public.', 400 );
		}

		return $repository;
	}

	/**
	 * @return RepositoryBrowseResult
	 */
	private function listRepositories(
		string $workspace,
		?BitbucketCredential $credential,
		?string $credentialId,
		bool $publicOnly,
		RepositoryBrowseRequest $browseRequest
	): RepositoryBrowseResult {
		$encodedWorkspace = rawurlencode( $workspace );
		$collectionPath   = '/2.0/repositories/' . $encodedWorkspace;
		$url              = self::API_BASE
			. $encodedWorkspace
			. '?'
			. http_build_query(
				array(
					'pagelen' => self::PER_PAGE,
					'fields'  => self::LIST_FIELDS,
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			);
		$seenUrls         = array();
		$repositories     = array();

		for ( $page = 1; ; ++$page ) {
			if ( ! $browseRequest->hasCapacity() ) {
				return $this->partialBrowseResult( $repositories, 503 );
			}

			if ( isset( $seenUrls[ $url ] ) ) {
				return $this->partialBrowseResult( $repositories, 422 );
			}

			if ( 1 < $page ) {
				try {
					$this->assertCollectionPageUrl( $url, $collectionPath );
				} catch ( RuntimeException $exception ) {
					return $this->partialBrowseResult( $repositories, (int) $exception->getCode() );
				}
			}

			$seenUrls[ $url ] = true;
			try {
				$response = $this->request(
					$url,
					$credential,
					'Bitbucket could not find that workspace.',
					$browseRequest->claimRemoteCall(),
					$browseRequest->getResponseSizeLimit(),
					504,
					422
				);
				$browseRequest->acceptResponseBody( $response->getBody() );
			} catch ( RuntimeException | InvalidArgumentException $exception ) {
				if ( $publicOnly
					&& null !== $credential
					&& in_array( (int) $exception->getCode(), array( 401, 403, 429 ), true )
				) {
					throw $exception;
				}

				if ( array() === $repositories ) {
					throw $exception;
				}

				return $this->partialBrowseResult( $repositories, (int) $exception->getCode() );
			}
			$data = json_decode( $response->getBody(), true, 512, JSON_BIGINT_AS_STRING );

			if ( ! is_array( $data )
				|| ! isset( $data['values'] )
				|| ! is_array( $data['values'] )
				|| ! array_is_list( $data['values'] )
			) {
				if ( array() === $repositories ) {
					throw new RuntimeException( 'Bitbucket returned an invalid repository list.', 422 );
				}

				return $this->partialBrowseResult( $repositories, 422 );
			}

			foreach ( $data['values'] as $item ) {
				$repository = $this->descriptorFromItem( $item, $credentialId, $workspace );

				if ( null === $repository || ( $publicOnly && $repository->private ) ) {
					continue;
				}

				$repositories[] = $repository;
				if ( RepositoryBrowseRequest::MAX_RESULTS <= count( $repositories ) ) {
					return $this->partialBrowseResult( $repositories, 206 );
				}
			}

			if ( ! array_key_exists( 'next', $data ) || null === $data['next'] ) {
				return new RepositoryBrowseResult( $this->deduplicateAndSort( $repositories ) );
			}

			if ( ! is_string( $data['next'] ) || '' === $data['next'] ) {
				return $this->partialBrowseResult( $repositories, 422 );
			}

			$url = $data['next'];
		}
	}

	/** @param list<RepositoryDescriptor> $repositories */
	private function partialBrowseResult( array $repositories, int $status ): RepositoryBrowseResult {
		if ( array() === $repositories ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Status is an internal fixed integer; the message is fixed and redacted.
			throw new RuntimeException( 'Bitbucket repository browsing could not continue safely.', $status );
		}

		$reason = match ( $status ) {
			401, 403 => RepositoryBrowseResult::AUTHORIZATION,
			429 => RepositoryBrowseResult::RATE_LIMIT,
			206, 413, 503, 504 => RepositoryBrowseResult::LIMIT,
			default => RepositoryBrowseResult::PROVIDER,
		};

		return new RepositoryBrowseResult( $this->deduplicateAndSort( $repositories ), $reason );
	}

	private function request(
		string $url,
		?BitbucketCredential $credential,
		string $notFoundMessage,
		float|int $timeout = 15,
		int $responseSize = 262144,
		int $transportStatus = 502,
		int $invalidStatus = 502
	): BitbucketApiResponse {
		try {
			$response = $this->api->get( $url, $credential, $timeout, $responseSize );
		} catch ( BitbucketApiException $exception ) {
			if ( BitbucketApiException::TRANSPORT_ERROR === $exception->getReason() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal fixed status selected by the call site; message is fixed.
				throw new RuntimeException( 'Bitbucket could not be reached. Please try again.', $transportStatus );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal fixed status selected by the call site; message is fixed.
			throw new RuntimeException( 'Bitbucket returned an invalid API response.', $invalidStatus );
		}

		$status = $response->getStatus();

		if ( 401 === $status ) {
			throw new RuntimeException( 'Bitbucket rejected the selected credential.', 401 );
		}

		if ( 403 === $status ) {
			throw new RuntimeException( 'Bitbucket denied repository access. Check credential permissions.', 403 );
		}

		if ( 404 === $status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal message selected by the caller.
			throw new RuntimeException( $notFoundMessage, 404 );
		}

		if ( 410 === $status ) {
			throw new RuntimeException( 'The requested Bitbucket repository resource is no longer available.', 410 );
		}

		if ( 429 === $status ) {
			throw new RuntimeException( 'Bitbucket rate-limited repository access. Try again later.', 429 );
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new RuntimeException( 'Bitbucket repository access failed. Please try again.', 502 );
		}

		return $response;
	}

	private function assertCollectionPageUrl( string $url, string $collectionPath ): void {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || $collectionPath !== ( $parts['path'] ?? null ) ) {
			throw new RuntimeException( 'Bitbucket returned an invalid repository pagination response.', 422 );
		}
	}

	private function descriptorFromItem(
		mixed $item,
		?string $credentialId,
		string $expectedWorkspace,
		bool $requireDefaultBranch = false
	): ?RepositoryDescriptor {
		if ( ! is_array( $item )
			|| ! is_string( $item['uuid'] ?? null )
			|| '' === trim( $item['uuid'] )
			|| ! is_string( $item['full_name'] ?? null )
			|| ! is_bool( $item['is_private'] ?? null )
		) {
			return null;
		}

		$fullName = trim( $item['full_name'] );

		try {
			$coordinates = BitbucketRepositoryCoordinates::fromFullName( $fullName );
			$workspace   = $coordinates->getWorkspace();
		} catch ( InvalidArgumentException ) {
			return null;
		}

		if ( 0 !== strcasecmp( $expectedWorkspace, $workspace ) ) {
			return null;
		}

		if ( ! is_array( $item['mainbranch'] ?? null )
			|| ! is_string( $item['mainbranch']['name'] ?? null )
			|| '' === trim( $item['mainbranch']['name'] )
		) {
			if ( $requireDefaultBranch ) {
				throw new RuntimeException( 'That Bitbucket repository has no default branch to deploy.', 400 );
			}

			return null;
		}

		return new RepositoryDescriptor(
			ProviderCode::parse( 'bb' ),
			$fullName,
			$coordinates->getRepositorySlug(),
			trim( $item['uuid'] ),
			$item['is_private'],
			trim( $item['mainbranch']['name'] ),
			$credentialId
		);
	}
	/**
	 * @param list<RepositoryDescriptor> $repositories Repositories to normalize.
	 * @return list<RepositoryDescriptor>
	 */
	private function deduplicateAndSort( array $repositories ): array {
		$unique    = array();
		$seenIds   = array();
		$seenNames = array();

		foreach ( $repositories as $repository ) {
			$idKey   = strtolower( $repository->providerRepositoryId );
			$nameKey = strtolower( $repository->locator );

			if ( isset( $seenIds[ $idKey ] ) || isset( $seenNames[ $nameKey ] ) ) {
				continue;
			}

			$seenIds[ $idKey ]     = true;
			$seenNames[ $nameKey ] = true;
			$unique[]              = $repository;
		}

		usort(
			$unique,
			static function ( RepositoryDescriptor $left, RepositoryDescriptor $right ): int {
				$order = strcasecmp( $left->locator, $right->locator );

				if ( 0 !== $order ) {
					return $order;
				}

				$order = strcmp( $left->locator, $right->locator );
				if ( 0 !== $order ) {
					return $order;
				}

				$order = strcmp( $left->providerRepositoryId, $right->providerRepositoryId );

				return 0 !== $order
					? $order
					: strcmp( $left->credentialId ?? '', $right->credentialId ?? '' );
			}
		);

		return $unique;
	}

	private function validateWorkspace( string $workspace ): string {
		$workspace = trim( $workspace );

		if ( 1 !== preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?$/', $workspace ) ) {
			throw new RuntimeException( 'Enter a valid Bitbucket workspace.', 400 );
		}

		return $workspace;
	}
}
