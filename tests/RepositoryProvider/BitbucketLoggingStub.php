<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use RAN\AddOn\Logging\LoggingFacade;

final class BitbucketLoggingStub implements LoggingFacade {

	public function log( string $message, array $context = array() ): void {
		unset( $message, $context );
	}

	public function logException( string $message, \Throwable $exception, array $context = array() ): void {
		unset( $message, $exception, $context );
	}
}
