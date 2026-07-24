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
		yield 'workspace' => array(
			'workspace',
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
}
