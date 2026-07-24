<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use RuntimeException;

final class BitbucketApiException extends RuntimeException {

	public const INVALID_URL      = 'invalid-url';
	public const TRANSPORT_ERROR  = 'transport-error';
	public const INVALID_RESPONSE = 'invalid-response';

	private function __construct(
		private readonly string $reason,
		string $message
	) {
		parent::__construct( $message );
	}

	public static function invalidUrl(): self {
		return new self( self::INVALID_URL, 'The Bitbucket API request URL is not allowed.' );
	}

	public static function transportError(): self {
		return new self( self::TRANSPORT_ERROR, 'Bitbucket could not be reached.' );
	}

	public static function invalidResponse(): self {
		return new self( self::INVALID_RESPONSE, 'Bitbucket returned an invalid API response.' );
	}

	public function getReason(): string {
		return $this->reason;
	}
}
