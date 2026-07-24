<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

require_once __DIR__ . '/BitbucketCredentialValidationTransportError.php';

function bitbucket_credential_validation_http_reset( mixed $response ): void {
	bitbucket_repository_http_queue( array( $response ) );
}

/**
 * Queue one response per Bitbucket HTTP request.
 *
 * @param list<mixed> $responses Responses returned in request order.
 */
function bitbucket_repository_http_queue( array $responses ): void {
	$GLOBALS['ran_booster_bitbucket_repository_responses']           = array_values( $responses );
	$GLOBALS['ran_booster_bitbucket_credential_validation_requests'] = array();
}

/**
 * @return list<array{url: string, arguments: array<string, mixed>}>
 */
function bitbucket_credential_validation_http_requests(): array {
	return $GLOBALS['ran_booster_bitbucket_credential_validation_requests'] ?? array();
}

/**
 * @param array<string, mixed> $arguments Request arguments.
 */
function wp_remote_get( string $url, array $arguments ): mixed {
	$GLOBALS['ran_booster_bitbucket_credential_validation_requests'][] = array(
		'url'       => $url,
		'arguments' => $arguments,
	);

	$responses = $GLOBALS['ran_booster_bitbucket_repository_responses'] ?? array();
	$response  = array_shift( $responses );

	$GLOBALS['ran_booster_bitbucket_repository_responses'] = $responses;

	return $response;
}

function is_wp_error( mixed $response ): bool {
	return $response instanceof BitbucketCredentialValidationTransportError;
}

/** @return array<string, int|string>|false */
function wp_parse_url( string $url ): array|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit-test stand-in for WordPress's wp_parse_url().
	return parse_url( $url );
}

/**
 * @param array<string, mixed> $response Response fixture.
 */
function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

/**
 * @param array<string, mixed> $response Response fixture.
 */
function wp_remote_retrieve_body( array $response ): string {
	return isset( $response['body'] ) && is_string( $response['body'] ) ? $response['body'] : '';
}
