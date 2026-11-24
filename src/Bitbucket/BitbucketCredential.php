<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final readonly class BitbucketCredential {

	private function __construct(
		private string $workspace,
		#[SensitiveParameter] private SensitiveParameterValue $email,
		#[SensitiveParameter] private SensitiveParameterValue $token
	) {
	}

	public static function from_material(
		mixed $workspace,
		#[SensitiveParameter] mixed $email,
		#[SensitiveParameter] mixed $token
	): self {
		if ( ! self::is_workspace( $workspace )
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
			new SensitiveParameterValue( $email ),
			new SensitiveParameterValue( $token )
		);
	}

	public function get_workspace(): string {
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

		$email = $this->email->getValue();
		$token = $this->token->getValue();

		if ( ! is_string( $email ) || ! is_string( $token ) ) {
			throw BitbucketCredentialException::unavailable();
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Bitbucket API tokens use HTTP Basic authentication.
		$headers['Authorization'] = 'Basic ' . base64_encode( $email . ':' . $token );
		$arguments['headers']     = $headers;

		return $arguments;
	}

	/** @return array{workspace: string} */
	public function __debugInfo(): array {
		return array(
			'workspace' => $this->workspace,
		);
	}

	/** @return array<string, mixed> */
	public function __serialize(): array {
		throw new LogicException( 'Bitbucket credentials cannot be serialized.' );
	}

	/** @param array<string, mixed> $data */
	public function __unserialize( #[SensitiveParameter] array $data ): void {
		unset( $data );

		throw new LogicException( 'Bitbucket credentials cannot be unserialized.' );
	}

	public function __clone(): void {
		throw new LogicException( 'Bitbucket credentials cannot be cloned.' );
	}

	private static function is_workspace( mixed $workspace ): bool {
		return is_string( $workspace )
			&& 1 === preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?\z/D', $workspace );
	}
}
