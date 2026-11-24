<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use RuntimeException;

final class BitbucketCredentialException extends RuntimeException {

	public const STORE_UNAVAILABLE = 'store-unavailable';
	public const NOT_USABLE        = 'not-usable';

	private function __construct(
		private readonly string $reason,
		string $message
	) {
		parent::__construct( $message );
	}

	public static function store_unavailable(): self {
		return new self( self::STORE_UNAVAILABLE, 'The Bitbucket credential store is unavailable.' );
	}

	public static function not_usable(): self {
		return new self( self::NOT_USABLE, 'The selected Bitbucket credential is unavailable or invalid.' );
	}

	public static function unavailable(): self {
		return self::not_usable();
	}

	public function get_reason(): string {
		return $this->reason;
	}

	public function is_store_unavailable(): bool {
		return self::STORE_UNAVAILABLE === $this->reason;
	}
}
