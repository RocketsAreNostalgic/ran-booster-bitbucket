<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

final readonly class BitbucketApiResponse {

	public function __construct(
		private int $status,
		private string $body
	) {
	}

	public function get_status(): int {
		return $this->status;
	}

	public function get_body(): string {
		return $this->body;
	}
}
