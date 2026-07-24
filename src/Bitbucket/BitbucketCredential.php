<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

final readonly class BitbucketCredential {

	private function __construct(
		private string $workspace,
		private string $authorization
	) {
	}

	public static function fromMaterial( mixed $workspace, mixed $email, mixed $token ): self {
		if ( ! self::isWorkspace( $workspace )
			|| ! is_string( $email )
			|| false === filter_var( $email, FILTER_VALIDATE_EMAIL )
			|| 1 === preg_match( '/[:\x00-\x1F\x7F]/', $email )
			|| ! is_string( $token )
			|| '' === trim( $token )
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $token )
		) {
			throw BitbucketCredentialException::unavailable();
		}

		return new self(
			$workspace,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Bitbucket API tokens use HTTP Basic authentication.
			'Basic ' . base64_encode( $email . ':' . $token )
		);
	}

	public function getWorkspace(): string {
		return $this->workspace;
	}

	/**
	 * @param array<string, mixed> $arguments WordPress HTTP request arguments.
	 * @return array<string, mixed>
	 */
	public function authorize( array $arguments ): array {
		$headers = $arguments['headers'] ?? array();

		if ( ! is_array( $headers ) ) {
			throw BitbucketCredentialException::unavailable();
		}

		$headers['Authorization'] = $this->authorization;
		$arguments['headers']     = $headers;

		return $arguments;
	}

	private static function isWorkspace( mixed $workspace ): bool {
		return is_string( $workspace )
			&& 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?$/', $workspace );
	}
}
