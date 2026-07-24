<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

final readonly class BitbucketApiClient {

	private const API_ORIGIN = 'https://api.bitbucket.org';
	private const API_PATH   = '/2.0/repositories/';

	public function get(
		string $url,
		?BitbucketCredential $credential = null,
		float|int $timeout = 15,
		int $responseSize = 262144
	): BitbucketApiResponse {
		$this->assertAllowedUrl( $url );

		$arguments = array(
			'timeout'             => $timeout,
			'redirection'         => 0,
			'limit_response_size' => $responseSize,
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
			if ( method_exists( $response, 'get_error_code' ) && 'http_request_failed' === $response->get_error_code() ) {
				throw BitbucketApiException::transportError();
			}

			throw BitbucketApiException::invalidResponse();
		}

		if ( ! is_array( $response )
			|| ! is_array( $response['response'] ?? null )
			|| ! is_int( $response['response']['code'] ?? null )
			|| ! is_string( $response['body'] ?? null )
		) {
			throw BitbucketApiException::invalidResponse();
		}

		return new BitbucketApiResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			wp_remote_retrieve_body( $response )
		);
	}

	private function assertAllowedUrl( string $url ): void {
		if ( '' === $url
			|| 1 === preg_match( '/[\x00-\x20\x7F]/', $url )
			|| str_contains( $url, '#' )
			|| ! str_starts_with( $url, self::API_ORIGIN . self::API_PATH )
		) {
			throw BitbucketApiException::invalidUrl();
		}

		$parts       = wp_parse_url( $url );
		$path        = is_array( $parts ) && is_string( $parts['path'] ?? null ) ? $parts['path'] : '';
		$decodedPath = rawurldecode( $path );

		if ( ! is_array( $parts )
			|| 'https' !== ( $parts['scheme'] ?? null )
			|| 'api.bitbucket.org' !== ( $parts['host'] ?? null )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['port'] )
			|| isset( $parts['fragment'] )
			|| ! str_starts_with( $path, self::API_PATH )
			|| 1 === preg_match( '/%(?:25|2e|2f|5c)/i', $path )
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $decodedPath )
			|| ! $this->hasCanonicalRepositoryPath( $decodedPath )
			|| $this->queryContainsCredential( $parts['query'] ?? null )
		) {
			throw BitbucketApiException::invalidUrl();
		}
	}

	private function hasCanonicalRepositoryPath( string $path ): bool {
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

	private function queryContainsCredential( mixed $query ): bool {
		if ( null === $query ) {
			return false;
		}

		if ( ! is_string( $query ) ) {
			return true;
		}

		foreach ( explode( '&', $query ) as $field ) {
			$key = strtolower( rawurldecode( explode( '=', $field, 2 )[0] ) );
			$key = explode( '[', $key, 2 )[0];

			if ( in_array( $key, array( 'access_token', 'api_token', 'api-token', 'app_password', 'app-password', 'token', 'password', 'secret', 'authorization', 'email', 'username' ), true ) ) {
				return true;
			}
		}

		return false;
	}
}
