<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\CredentialValidator;

final readonly class BitbucketCredentialValidator implements CredentialValidator {

	private const API_BASE = 'https://api.bitbucket.org/2.0/repositories/';

	public function __construct(
		private BitbucketCredentialLoader $credentials,
		private BitbucketApiClient $api
	) {
	}

	public function validate_credential( string $credentialId, float|int $timeout = 15, int $response_size = 262144 ): CredentialValidationResult { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Provider API 13 preserves public parameter and named-argument contracts.
		try {
			$credential = $this->credentials->load( $credentialId ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Provider API 13 preserves public parameter and named-argument contracts.
		} catch ( BitbucketCredentialException ) {
			return CredentialValidationResult::invalid();
		}

		$url = self::API_BASE . rawurlencode( $credential->get_workspace() ) . '?pagelen=1';

		try {
			$response = $this->api->get( $url, $credential, $timeout, $response_size );
		} catch ( BitbucketApiException $exception ) {
			if ( BitbucketApiException::TRANSPORT_ERROR === $exception->get_reason() ) {
				return CredentialValidationResult::unavailable();
			}

			return CredentialValidationResult::invalidResponse();
		}

		$status = $response->get_status();

		if ( in_array( $status, array( 401, 403, 404 ), true ) ) {
			return CredentialValidationResult::invalid();
		}

		if ( 429 === $status ) {
			return CredentialValidationResult::rateLimited();
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
			return CredentialValidationResult::invalidResponse();
		}

		return CredentialValidationResult::valid();
	}
}
