<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

final readonly class BitbucketApiResponse {

	public function __construct(
		private int $status,
		private string $body
	) {
	}

	public function getStatus(): int {
		return $this->status;
	}

	public function getBody(): string {
		return $this->body;
	}
}
