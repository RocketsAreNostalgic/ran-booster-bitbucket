<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use SensitiveParameter;

final readonly class BitbucketApiClient {

	private const API_ORIGIN                  = 'https://api.bitbucket.org';
	private const API_PATH                    = '/2.0/repositories/';
	private const CREDENTIAL_QUERY_PARAMETERS = array(
		'access_token',
		'api_key',
		'api_token',
		'api-token',
		'apikey',
		'app_password',
		'app-password',
		'auth',
		'authorization',
		'bearer',
		'email',
		'jwt',
		'key',
		'password',
		'secret',
		'sig',
		'signature',
		'token',
		'username',
	);
	private const COLLECTION_QUERY_PARAMETERS = array(
		'after',
		'fields',
		'page',
		'pagelen',
	);
	private const RESOURCE_QUERY_PARAMETERS   = array(
		'fields',
	);

	public function get(
		string $url,
		#[SensitiveParameter] ?BitbucketCredential $credential = null,
		float|int $timeout = 15,
		int $response_size = 262144
	): BitbucketApiResponse {
		$this->assert_allowed_url( $url );

		$arguments = array(
			'timeout'             => $timeout,
			'redirection'         => 0,
			'limit_response_size' => $response_size,
			'reject_unsafe_urls'  => true,
			'headers'             => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'RAN-Booster',
			),
		);

		if ( null !== $credential ) {
			$arguments = $credential->authorize( $arguments );
		}

		$response = wp_remote_get( $url, $arguments );

		if ( is_wp_error( $response ) ) {
			if ( 'http_request_not_executed' === $response->get_error_code() ) {
				throw BitbucketApiException::blocked_by_site();
			}

			throw BitbucketApiException::transport_error();
		}

		if ( ! is_array( $response )
			|| ! is_array( $response['response'] ?? null )
			|| ! is_int( $response['response']['code'] ?? null )
			|| ! is_string( $response['body'] ?? null )
		) {
			throw BitbucketApiException::invalid_response();
		}

		return new BitbucketApiResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			wp_remote_retrieve_body( $response )
		);
	}

	private function assert_allowed_url( string $url ): void {
		if ( '' === $url
			|| 1 === preg_match( '/[\x00-\x20\x7F]/', $url )
			|| str_contains( $url, '#' )
			|| ! str_starts_with( $url, self::API_ORIGIN . self::API_PATH )
		) {
			throw BitbucketApiException::invalid_url();
		}

		$parts        = wp_parse_url( $url );
		$path         = is_array( $parts ) && is_string( $parts['path'] ?? null ) ? $parts['path'] : '';
		$decoded_path = rawurldecode( $path );

		if ( ! is_array( $parts )
			|| 'https' !== ( $parts['scheme'] ?? null )
			|| 'api.bitbucket.org' !== ( $parts['host'] ?? null )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['port'] )
			|| isset( $parts['fragment'] )
			|| ! str_starts_with( $path, self::API_PATH )
			|| 1 === preg_match( '/%(?:25|2e|2f|5c)/i', $path )
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $decoded_path )
			|| ! $this->has_canonical_repository_path( $decoded_path )
			|| ! $this->has_allowed_query( $parts['query'] ?? null, $decoded_path )
		) {
			throw BitbucketApiException::invalid_url();
		}
	}

	private function has_canonical_repository_path( string $path ): bool {
		if ( ! str_starts_with( $path, self::API_PATH ) || str_contains( $path, '\\' ) ) {
			return false;
		}

		$segments = explode( '/', substr( $path, strlen( self::API_PATH ) ) );

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	private function has_allowed_query( mixed $query, string $decoded_path ): bool {
		if ( null === $query ) {
			return true;
		}

		if ( ! is_string( $query ) || '' === $query ) {
			return false;
		}

		$allowed_parameters = $this->allowed_query_parameters( $decoded_path );

		foreach ( explode( '&', $query ) as $field ) {
			$raw_key        = explode( '=', $field, 2 )[0];
			$decoded_key    = rawurldecode( $raw_key );
			$normalized_key = strtolower( explode( '[', $decoded_key, 2 )[0] );

			if ( in_array( $normalized_key, self::CREDENTIAL_QUERY_PARAMETERS, true ) ) {
				return false;
			}

			if ( ! in_array( $raw_key, $allowed_parameters, true ) ) {
				return false;
			}
		}

		return true;
	}

	/** @return list<string> */
	private function allowed_query_parameters( string $decoded_path ): array {
		$segments = explode( '/', substr( $decoded_path, strlen( self::API_PATH ) ) );
		$count    = count( $segments );

		if ( 1 === $count || 2 === $count ) {
			return self::COLLECTION_QUERY_PARAMETERS;
		}

		if ( 4 === $count && 'commit' === $segments[2] ) {
			return self::RESOURCE_QUERY_PARAMETERS;
		}

		if ( 5 <= $count && 'refs' === $segments[2] && in_array( $segments[3], array( 'branches', 'tags' ), true ) ) {
			return self::RESOURCE_QUERY_PARAMETERS;
		}

		return array();
	}
}
