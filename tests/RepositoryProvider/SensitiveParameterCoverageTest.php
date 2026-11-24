<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket\Tests\RepositoryProvider;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RAN\Booster\Bitbucket\BitbucketCredential;
use RAN\Booster\Bitbucket\BitbucketCredentialException;
use RAN\Booster\Bitbucket\BitbucketCredentialPolicy;
use RAN\Booster\Bitbucket\BitbucketWebhookPolicy;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use SplFileInfo;

final class SensitiveParameterCoverageTest extends TestCase {

	private const SENSITIVE_NAME_PATTERN = '/(?:^|_)(?:token|secret|password|passwd|email|auth|authorization|bearer|jwt|sig|signature|api_?key)(?:$|_)/i';

	/**
	 * Parameters with generic names that carry raw secrets, emails, or credential constants.
	 *
	 * @var array<class-string, array<string, list<string>>>
	 */
	private const EXPLICIT_SENSITIVE_PARAMETERS = array(
		BitbucketCredential::class       => array(
			'__construct'   => array( 'email', 'token' ),
			'from_material' => array( 'email', 'token' ),
			'__unserialize' => array( 'data' ),
		),
		BitbucketCredentialPolicy::class => array(
			'normalize_credential'           => array( 'metadata', 'secret' ),
			'credential_from_constants'      => array( 'constants' ),
			'required_string'                => array( 'value' ),
			'has_email_delimiter_or_control' => array( 'email' ),
			'has_ascii_control'              => array( 'value' ),
		),
		BitbucketWebhookPolicy::class    => array(
			'normalize_webhook'      => array( 'secret' ),
			'webhook_from_constants' => array( 'constants' ),
			'assert_secret'          => array( 'secret' ),
			'required_secret'        => array( 'secret' ),
		),
	);

	public function test_every_secret_carrying_parameter_in_src_has_sensitive_parameter_attribute(): void {
		$src_root = dirname( __DIR__, 2 ) . '/src/Bitbucket';
		$classes  = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src_root, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $entry ) {
			if ( ! $entry instanceof SplFileInfo || ! $entry->isFile() || 'php' !== $entry->getExtension() ) {
				continue;
			}

			$relative   = substr( $entry->getPathname(), strlen( $src_root ) + 1, -4 );
			$class_name = 'RAN\\Booster\\Bitbucket\\' . str_replace( '/', '\\', $relative );
			self::assertTrue( class_exists( $class_name ), 'Expected autoloadable product class: ' . $class_name );
			$classes[] = $class_name;
		}

		sort( $classes, SORT_STRING );
		self::assertGreaterThanOrEqual( 16, count( $classes ) );

		$missing = array();
		$checked = array();

		foreach ( $classes as $class_name ) {
			$reflection = new ReflectionClass( $class_name );

			foreach ( $reflection->getMethods() as $method ) {
				if ( $method->getDeclaringClass()->getName() !== $class_name ) {
					continue;
				}

				foreach ( $method->getParameters() as $parameter ) {
					if ( ! $this->is_sensitive_parameter( $parameter ) ) {
						continue;
					}

					$label     = $class_name . '::' . $method->getName() . '( $' . $parameter->getName() . ' )';
					$checked[] = $label;

					if ( array() === $parameter->getAttributes( SensitiveParameter::class ) ) {
						$missing[] = $label;
					}
				}
			}
		}

		foreach ( self::EXPLICIT_SENSITIVE_PARAMETERS as $class_name => $methods ) {
			$reflection = new ReflectionClass( $class_name );

			foreach ( $methods as $method_name => $parameter_names ) {
				self::assertTrue( $reflection->hasMethod( $method_name ), 'Missing expected method: ' . $class_name . '::' . $method_name );
				$method     = $reflection->getMethod( $method_name );
				$parameters = array();
				foreach ( $method->getParameters() as $parameter ) {
					$parameters[ $parameter->getName() ] = $parameter;
				}

				foreach ( $parameter_names as $parameter_name ) {
					$label = $class_name . '::' . $method_name . '( $' . $parameter_name . ' )';
					self::assertArrayHasKey( $parameter_name, $parameters, 'Missing expected parameter: ' . $label );
					$checked[] = $label;

					if ( array() === $parameters[ $parameter_name ]->getAttributes( SensitiveParameter::class ) ) {
						$missing[] = $label;
					}
				}
			}
		}

		self::assertNotEmpty( $checked );
		self::assertSame(
			array(),
			array_values( array_unique( $missing ) ),
			'Secret-carrying parameters in src/ must declare #[\\SensitiveParameter].'
		);
	}

	public function test_exceptions_thrown_from_secret_carrying_methods_redact_trace_arguments(): void {
		$email_canary  = 'trace-email-canary@example.test';
		$token_canary  = 'trace-token-canary-0001';
		$secret_canary = 'trace-webhook-secret-canary';

		$cases = array(
			'BitbucketCredential::from_material'        => static function () use ( $email_canary, $token_canary ): void {
				BitbucketCredential::from_material( 'invalid workspace', $email_canary, $token_canary );
			},
			'BitbucketCredentialPolicy::normalize_credential' => static function () use ( $email_canary, $token_canary ): void {
				( new BitbucketCredentialPolicy() )->normalize_credential(
					array(
						'label'         => 'Synthetic',
						'kind'          => 'api-token',
						'configuration' => array(
							'workspace' => 'invalid workspace',
							'email'     => $email_canary,
						),
					),
					$token_canary
				);
			},
			'BitbucketCredentialPolicy::credential_from_constants' => static function () use ( $email_canary, $token_canary ): void {
				( new BitbucketCredentialPolicy() )->credential_from_constants(
					array(
						'RAN_BOOSTER_BITBUCKET_WORKSPACE' => '',
						'RAN_BOOSTER_BITBUCKET_EMAIL'     => $email_canary,
						'RAN_BOOSTER_BITBUCKET_TOKEN'     => $token_canary,
					)
				);
			},
			'BitbucketWebhookPolicy::normalize_webhook' => static function () use ( $secret_canary ): void {
				( new BitbucketWebhookPolicy() )->normalize_webhook(
					array(
						'label'        => 'Synthetic',
						'scope'        => 'owner',
						'target'       => 'acme',
						'authority_id' => '',
					),
					$secret_canary
				);
			},
		);

		foreach ( $cases as $context => $trigger ) {
			try {
				$trigger();
				self::fail( 'Expected exception from ' . $context );
			} catch ( BitbucketCredentialException | RuntimeException $exception ) {
				$this->assert_trace_contains_no_canary(
					$exception->getTrace(),
					array( $email_canary, $token_canary, $secret_canary ),
					$context
				);
			}
		}
	}

	private function is_sensitive_parameter( ReflectionParameter $parameter ): bool {
		if ( 1 === preg_match( self::SENSITIVE_NAME_PATTERN, $parameter->getName() ) ) {
			return true;
		}

		$type  = $parameter->getType();
		$types = $type instanceof ReflectionUnionType
			? $type->getTypes()
			: ( $type instanceof ReflectionNamedType ? array( $type ) : array() );

		foreach ( $types as $named ) {
			if ( ! $named instanceof ReflectionNamedType ) {
				continue;
			}

			if ( in_array( $named->getName(), array( BitbucketCredential::class, SensitiveParameterValue::class ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $trace
	 * @param list<string>                     $canaries
	 */
	private function assert_trace_contains_no_canary( array $trace, array $canaries, string $context ): void {
		foreach ( $trace as $frame ) {
			$args = $frame['args'] ?? array();
			if ( ! is_array( $args ) ) {
				continue;
			}

			foreach ( $args as $arg ) {
				$this->assert_value_contains_no_canary( $arg, $canaries, $context );
			}
		}
	}

	/** @param list<string> $canaries */
	private function assert_value_contains_no_canary( mixed $value, array $canaries, string $context ): void {
		if ( $value instanceof SensitiveParameterValue ) {
			return;
		}

		if ( is_string( $value ) ) {
			foreach ( $canaries as $canary ) {
				self::assertStringNotContainsString( $canary, $value, $context );
			}
			return;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$this->assert_value_contains_no_canary( $item, $canaries, $context );
			}
		}
	}
}
