<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

final class BitbucketCredentialValidationTransportError {

	public function __construct( private string $code = 'http_request_failed' ) {
	}

	public function get_error_code(): string {
		return $this->code;
	}
}
