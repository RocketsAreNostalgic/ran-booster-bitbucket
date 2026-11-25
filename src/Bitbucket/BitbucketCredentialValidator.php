<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\CredentialValidator;
use RuntimeException;

final readonly class BitbucketCredentialValidator implements CredentialValidator {

	private const API_BASE = 'https://api.bitbucket.org/2.0/repositories/';

	public function __construct(
		private BitbucketCredentialLoader $credentials,
		private BitbucketApiClient $api
	) {
	}

	public function validate_credential( string $credential_id, float|int $timeout = 15, int $response_size = 262144 ): CredentialValidationResult {
		try {
			$credential = $this->credentials->load( $credential_id );
		} catch ( BitbucketCredentialException ) {
			return CredentialValidationResult::unavailable();
		}

		$url = self::API_BASE . rawurlencode( $credential->get_workspace() ) . '?pagelen=1';

		try {
			$response = $this->api->get( $url, $credential, $timeout, $response_size );
		} catch ( BitbucketApiException $exception ) {
			if ( BitbucketApiException::INVALID_URL === $exception->get_reason() ) {
				throw new RuntimeException( 'The Bitbucket API request URL is not allowed.', 500 );
			}

			if ( in_array(
				$exception->get_reason(),
				array( BitbucketApiException::TRANSPORT_ERROR, BitbucketApiException::BLOCKED_BY_SITE ),
				true
			) ) {
				return CredentialValidationResult::unavailable();
			}

			return CredentialValidationResult::invalid_response();
		}

		$status = $response->get_status();

		if ( in_array( $status, array( 401, 403, 404 ), true ) ) {
			return CredentialValidationResult::invalid();
		}

		if ( 429 === $status ) {
			return CredentialValidationResult::rate_limited();
		}

		if ( $status < 200 || $status >= 300 ) {
			return CredentialValidationResult::unavailable();
		}

		$body = json_decode( $response->get_body() );

		if ( ! is_object( $body )
			|| ! isset( $body->values )
			|| ! is_array( $body->values )
			|| ! array_is_list( $body->values )
		) {
			return CredentialValidationResult::invalid_response();
		}

		return CredentialValidationResult::valid();
	}
}
