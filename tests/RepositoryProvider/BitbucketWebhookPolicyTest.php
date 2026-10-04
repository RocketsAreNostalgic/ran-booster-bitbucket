<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\Bitbucket\BitbucketWebhookPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RuntimeException;

final class BitbucketWebhookPolicyTest extends TestCase {

	private const SECRET = 'bitbucket-webhook-policy-secret-0001';

	public function test_named_arguments_preserve_repository_authority_and_locator_boundaries(): void {
		$policy       = new BitbucketWebhookPolicy();
		$verification = new SignedWebhookVerification(
			ProviderCode::parse( 'bb' ),
			array(
				array(
					'id'           => 'test-profile',
					'scope'        => 'repository',
					'target'       => 'workspace/repository',
					'authority_id' => 'stable-repository-id',
				),
			)
		);

		self::assertTrue( $policy->authorize_webhook( verification: $verification, repository_authority_id: 'stable-repository-id', repository: 'workspace/repository' ) );
		self::assertFalse( $policy->authorize_webhook( verification: $verification, repository_authority_id: 'other-repository-id', repository: 'workspace/repository' ) );
		self::assertTrue( $policy->repository_target_matches( target: '/WORKSPACE/Repository/', repository_locator: 'workspace/repository' ) );
		self::assertFalse( $policy->repository_target_matches( target: 'workspace/repository', repository_locator: 'workspace/other' ) );
	}

	/** @return iterable<string, array{string, string, string}> */
	public static function invalid_targets(): iterable {
		yield 'owner' => array(
			'owner',
			'invalid workspace',
			'Workspace-scoped webhook secrets require a valid provider workspace.',
		);
		yield 'repository' => array(
			'repository',
			'workspace/repository/extra',
			'Repository-scoped webhook secrets require a workspace/repository target.',
		);
	}

	#[DataProvider( 'invalid_targets' )]
	public function test_invalid_targets_use_bitbucket_workspace_terminology( string $scope, string $target, string $message ): void {
		$policy = new BitbucketWebhookPolicy();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( $message );

		$policy->normalize_webhook(
			array(
				'label'        => 'Test',
				'scope'        => $scope,
				'target'       => $target,
				'authority_id' => '',
			),
			self::SECRET
		);
	}

	/** @return iterable<string, array{string}> */
	public static function unsupported_scopes(): iterable {
		yield 'removed global scope' => array( 'global' );
		yield 'provider label is not a logical scope' => array( 'workspace' );
	}

	#[DataProvider( 'unsupported_scopes' )]
	public function test_only_universal_logical_scopes_are_accepted( string $scope ): void {
		$policy = new BitbucketWebhookPolicy();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Webhook secret scope is not supported by this provider.' );

		$policy->normalize_webhook(
			array(
				'label'        => 'Test',
				'scope'        => $scope,
				'target'       => 'workspace',
				'authority_id' => '',
			),
			self::SECRET
		);
	}

	public function test_legacy_global_constant_is_not_declared_or_read(): void {
		$policy = new BitbucketWebhookPolicy();

		self::assertSame( array(), $policy->get_constant_names() );
		self::assertNull(
			$policy->webhook_from_constants(
				array( 'RAN_BOOSTER_BITBUCKET_WEBHOOK_SECRET' => self::SECRET )
			)
		);
	}
}
