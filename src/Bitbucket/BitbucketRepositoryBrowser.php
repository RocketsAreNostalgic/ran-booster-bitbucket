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
		if ( RepositoryBrowseMode::PUBLIC_OWNER === $request->get_mode() ) {
			$workspace  = $this->validate_workspace( (string) $request->get_owner() );
			$credential = null;

			if ( null !== $request->get_credential_id() ) {
				try {
					$credential = $this->credentials->load( $request->get_credential_id() );
				} catch ( BitbucketCredentialException ) {
					throw new RuntimeException( 'The selected Bitbucket credential is unavailable or invalid.', 400 );
				}
			}

			return $this->list_repositories(
				$workspace,
				$credential,
				null,
				true,
				$request
			);
		}

		$credential_id = (string) $request->get_credential_id();
		try {
			$credential = $this->credentials->load( $credential_id );
		} catch ( BitbucketCredentialException ) {
			throw new RuntimeException( 'The selected Bitbucket credential is unavailable or invalid.', 400 );
		}

		return $this->list_repositories(
			$credential->get_workspace(),
			$credential,
			$credential_id,
			false,
			$request
		);
	}

	public function repository(
		string $full_name,
		?string $credential_id = null,
		float|int $timeout = 15,
		int $response_size = 262144,
		bool $public_only = false
	): RepositoryDescriptor {
		try {
			$coordinates = BitbucketRepositoryCoordinates::from_full_name( $full_name );
		} catch ( InvalidArgumentException ) {
			throw new RuntimeException( 'Enter a valid Bitbucket repository in workspace/repository form.', 400 );
		}

		$workspace              = $coordinates->get_workspace();
		$repository_slug        = $coordinates->get_repository_slug();
		$credential             = null;
		$resolved_credential_id = null;

		if ( null !== $credential_id ) {
			try {
				$credential = $this->credentials->load( $credential_id );
			} catch ( BitbucketCredentialException ) {
				throw new RuntimeException( 'The selected Bitbucket credential is unavailable or invalid.', 400 );
			}

			if ( ! $public_only && 0 !== strcasecmp( $workspace, $credential->get_workspace() ) ) {
				throw new RuntimeException( 'The selected Bitbucket credential belongs to another workspace.', 400 );
			}

			$resolved_credential_id = trim( $credential_id );
		}

		$url = self::API_BASE
			. rawurlencode( $workspace )
			. '/'
			. rawurlencode( $repository_slug )
			. '?'
			. http_build_query( array( 'fields' => self::LOOKUP_FIELDS ), '', '&', PHP_QUERY_RFC3986 );

		$response = $this->request(
			$url,
			$credential,
			'Bitbucket could not find that repository, or the selected credential cannot access it.',
			$timeout,
			$response_size
		);
		$item     = json_decode( $response->get_body(), true, 512, JSON_BIGINT_AS_STRING );

		$repository = $this->descriptor_from_item( $item, $resolved_credential_id, $workspace, true );

		if ( null === $repository || ! $coordinates->matches_full_name( $repository->locator ) ) {
			throw new RuntimeException( 'Bitbucket returned an invalid repository response.', 502 );
		}

		if ( $public_only && $repository->private ) {
			throw new RuntimeException( 'The selected Bitbucket repository is not public.', 400 );
		}

		return $repository;
	}

	/**
	 * @return RepositoryBrowseResult
	 */
	private function list_repositories(
		string $workspace,
		?BitbucketCredential $credential,
		?string $credential_id,
		bool $public_only,
		RepositoryBrowseRequest $browse_request
	): RepositoryBrowseResult {
		$encoded_workspace = rawurlencode( $workspace );
		$collection_path   = '/2.0/repositories/' . $encoded_workspace;
		$url               = self::API_BASE
			. $encoded_workspace
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
		$seen_urls         = array();
		$repositories      = array();

		for ( $page = 1; ; ++$page ) {
			if ( ! $browse_request->has_capacity() ) {
				return $this->partial_browse_result( $repositories, 503 );
			}

			if ( isset( $seen_urls[ $url ] ) ) {
				return $this->partial_browse_result( $repositories, 422 );
			}

			if ( 1 < $page ) {
				try {
					$this->assert_collection_page_url( $url, $collection_path );
				} catch ( RuntimeException $exception ) {
					return $this->partial_browse_result( $repositories, (int) $exception->getCode() );
				}
			}

			$seen_urls[ $url ] = true;
			try {
				$response = $this->request(
					$url,
					$credential,
					'Bitbucket could not find that workspace.',
					$browse_request->claim_remote_call(),
					$browse_request->get_response_size_limit(),
					504,
					422
				);
				$browse_request->accept_response_body( $response->get_body() );
			} catch ( RuntimeException | InvalidArgumentException $exception ) {
				if ( $public_only
					&& null !== $credential
					&& in_array( (int) $exception->getCode(), array( 401, 403, 429 ), true )
				) {
					throw $exception;
				}

				if ( array() === $repositories ) {
					throw $exception;
				}

				return $this->partial_browse_result( $repositories, (int) $exception->getCode() );
			}
			$data = json_decode( $response->get_body(), true, 512, JSON_BIGINT_AS_STRING );

			if ( ! is_array( $data )
				|| ! isset( $data['values'] )
				|| ! is_array( $data['values'] )
				|| ! array_is_list( $data['values'] )
			) {
				if ( array() === $repositories ) {
					throw new RuntimeException( 'Bitbucket returned an invalid repository list.', 422 );
				}

				return $this->partial_browse_result( $repositories, 422 );
			}

			foreach ( $data['values'] as $item ) {
				$repository = $this->descriptor_from_item( $item, $credential_id, $workspace );

				if ( null === $repository || ( $public_only && $repository->private ) ) {
					continue;
				}

				$repositories[] = $repository;
				if ( RepositoryBrowseRequest::MAX_RESULTS <= count( $repositories ) ) {
					return $this->partial_browse_result( $repositories, 206 );
				}
			}

			if ( ! array_key_exists( 'next', $data ) || null === $data['next'] ) {
				return new RepositoryBrowseResult( $this->deduplicate_and_sort( $repositories ) );
			}

			if ( ! is_string( $data['next'] ) || '' === $data['next'] ) {
				return $this->partial_browse_result( $repositories, 422 );
			}

			$url = $data['next'];
		}
	}

	/** @param list<RepositoryDescriptor> $repositories */
	private function partial_browse_result( array $repositories, int $status ): RepositoryBrowseResult {
		if ( array() === $repositories ) {
			throw new RuntimeException( 'Bitbucket repository browsing could not continue safely.', $status );
		}

		$reason = match ( $status ) {
			401, 403 => RepositoryBrowseResult::AUTHORIZATION,
			429 => RepositoryBrowseResult::RATE_LIMIT,
			206, 413, 503, 504 => RepositoryBrowseResult::LIMIT,
			default => RepositoryBrowseResult::PROVIDER,
		};

		return new RepositoryBrowseResult( $this->deduplicate_and_sort( $repositories ), $reason );
	}

	private function request(
		string $url,
		?BitbucketCredential $credential,
		string $not_found_message,
		float|int $timeout = 15,
		int $response_size = 262144,
		int $transport_status = 502,
		int $invalid_status = 502
	): BitbucketApiResponse {
		try {
			$response = $this->api->get( $url, $credential, $timeout, $response_size );
		} catch ( BitbucketApiException $exception ) {
			if ( BitbucketApiException::TRANSPORT_ERROR === $exception->get_reason() ) {
				throw new RuntimeException( 'Bitbucket could not be reached. Please try again.', $transport_status );
			}

			throw new RuntimeException( 'Bitbucket returned an invalid API response.', $invalid_status );
		}

		$status = $response->get_status();

		if ( 401 === $status ) {
			throw new RuntimeException( 'Bitbucket rejected the selected credential.', 401 );
		}

		if ( 403 === $status ) {
			throw new RuntimeException( 'Bitbucket denied repository access. Check credential permissions.', 403 );
		}

		if ( 404 === $status ) {
			throw new RuntimeException( $not_found_message, 404 );
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

	private function assert_collection_page_url( string $url, string $collection_path ): void {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ( $parts['path'] ?? null ) !== $collection_path ) {
			throw new RuntimeException( 'Bitbucket returned an invalid repository pagination response.', 422 );
		}
	}

	private function descriptor_from_item(
		mixed $item,
		?string $credential_id,
		string $expected_workspace,
		bool $require_default_branch = false
	): ?RepositoryDescriptor {
		if ( ! is_array( $item )
			|| ! is_string( $item['uuid'] ?? null )
			|| '' === trim( $item['uuid'] )
			|| ! is_string( $item['full_name'] ?? null )
			|| ! is_bool( $item['is_private'] ?? null )
		) {
			return null;
		}

		$full_name = trim( $item['full_name'] );

		try {
			$coordinates = BitbucketRepositoryCoordinates::from_full_name( $full_name );
			$workspace   = $coordinates->get_workspace();
		} catch ( InvalidArgumentException ) {
			return null;
		}

		if ( 0 !== strcasecmp( $expected_workspace, $workspace ) ) {
			return null;
		}

		if ( ! is_array( $item['mainbranch'] ?? null )
			|| ! is_string( $item['mainbranch']['name'] ?? null )
			|| '' === trim( $item['mainbranch']['name'] )
		) {
			if ( $require_default_branch ) {
				throw new RuntimeException( 'That Bitbucket repository has no default branch to deploy.', 400 );
			}

			return null;
		}

		return new RepositoryDescriptor(
			ProviderCode::parse( 'bb' ),
			$full_name,
			$coordinates->get_repository_slug(),
			trim( $item['uuid'] ),
			$item['is_private'],
			trim( $item['mainbranch']['name'] ),
			$credential_id
		);
	}
	/**
	 * @param list<RepositoryDescriptor> $repositories Repositories to normalize.
	 * @return list<RepositoryDescriptor>
	 */
	private function deduplicate_and_sort( array $repositories ): array {
		$unique     = array();
		$seen_ids   = array();
		$seen_names = array();

		foreach ( $repositories as $repository ) {
			$id_key   = strtolower( $repository->provider_repository_id );
			$name_key = strtolower( $repository->locator );

			if ( isset( $seen_ids[ $id_key ] ) || isset( $seen_names[ $name_key ] ) ) {
				continue;
			}

			$seen_ids[ $id_key ]     = true;
			$seen_names[ $name_key ] = true;
			$unique[]                = $repository;
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

				$order = strcmp( $left->provider_repository_id, $right->provider_repository_id );

				return 0 !== $order
					? $order
					: strcmp( $left->credential_id ?? '', $right->credential_id ?? '' );
			}
		);

		return $unique;
	}

	private function validate_workspace( string $workspace ): string {
		$workspace = trim( $workspace );

		if ( 1 !== preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?$/', $workspace ) ) {
			throw new RuntimeException( 'Enter a valid Bitbucket workspace.', 400 );
		}

		return $workspace;
	}
}
