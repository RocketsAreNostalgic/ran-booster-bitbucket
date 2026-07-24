<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use RuntimeException;

final class BitbucketCredentialException extends RuntimeException {

	public static function unavailable(): self {
		return new self( 'The selected Bitbucket credential is unavailable or invalid.' );
	}
}
