<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\Bitbucket\BitbucketWebhookPolicy;
use RuntimeException;

final class BitbucketWebhookPolicyTest extends TestCase {

	private const SECRET = 'bitbucket-webhook-policy-secret-0001';

	/** @return iterable<string, array{string, string, string}> */
	public static function invalidTargets(): iterable {
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

	#[DataProvider( 'invalidTargets' )]
	public function testInvalidTargetsUseBitbucketWorkspaceTerminology( string $scope, string $target, string $message ): void {
		$policy = new BitbucketWebhookPolicy();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( $message );

		$policy->normalizeWebhook(
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
	public static function unsupportedScopes(): iterable {
		yield 'removed global scope' => array( 'global' );
		yield 'provider label is not a logical scope' => array( 'workspace' );
	}

	#[DataProvider( 'unsupportedScopes' )]
	public function testOnlyUniversalLogicalScopesAreAccepted( string $scope ): void {
		$policy = new BitbucketWebhookPolicy();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Webhook secret scope is not supported by this provider.' );

		$policy->normalizeWebhook(
			array(
				'label'        => 'Test',
				'scope'        => $scope,
				'target'       => 'workspace',
				'authority_id' => '',
			),
			self::SECRET
		);
	}

	public function testLegacyGlobalConstantIsNotDeclaredOrRead(): void {
		$policy = new BitbucketWebhookPolicy();

		self::assertSame( array(), $policy->getConstantNames() );
		self::assertNull(
			$policy->webhookFromConstants(
				array( 'RAN_BOOSTER_BITBUCKET_WEBHOOK_SECRET' => self::SECRET )
			)
		);
	}
}
