<?php

declare(strict_types=1);

namespace Tests\Plugin;

use PHPUnit\Framework\TestCase;

final class CoreContractTest extends TestCase {
	public function testConfiguredCoreCheckoutPublishesTheRequiredApiGeneration(): void {
		$coreRoot          = getenv( 'RAN_BOOSTER_CORE_PATH' );
		$coreRoot          = false === $coreRoot || '' === $coreRoot
			? dirname( __DIR__, 3 ) . '/ran-booster'
			: rtrim( $coreRoot, '/\\' );
		$entryFile         = $coreRoot . '/ran-booster.php';
		$documentationFile = $coreRoot . '/views/documentation.php';
		$core              = file_get_contents( $entryFile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local contract fixture.
		$documentation     = file_get_contents( $documentationFile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local contract fixture.

		self::assertIsString( $core, 'A RAN Booster entry file is required at ' . $entryFile );
		self::assertIsString( $documentation, 'A RAN Booster documentation view is required at ' . $documentationFile );
		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_PROVIDER_API_VERSION',\\s*11\\s*\\)/",
			$core
		);
		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_ADDON_API_VERSION',\\s*16\\s*\\)/",
			$core
		);
		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION',\\s*2\\s*\\)/",
			$core
		);
		self::assertStringNotContainsString( 'RAN_BOOSTER_LOGGING_API_VERSION', $core );
		self::assertStringContainsString( 'ran_booster_documentation_sections_after_provider_', $documentation );

		$certification = $this->certification();
		$commit        = shell_exec( 'git -C ' . escapeshellarg( $coreRoot ) . ' rev-parse HEAD' );
		$tag           = shell_exec( 'git -C ' . escapeshellarg( $coreRoot ) . ' describe --tags --exact-match HEAD' );
		self::assertIsString( $commit );
		self::assertIsString( $tag );
		self::assertSame( $certification['commit'], trim( $commit ) );
		self::assertSame( $certification['tag'], trim( $tag ) );
	}

	public function testInstalledProofSeparatesTagTargetFromArchiveSource(): void {
		$workflow = $this->workflow( 'installed-proof.yml' );

		self::assertStringContainsString( '.extra["ran-booster-core-certification"].commit', $workflow );
		self::assertStringContainsString( '.extra["ran-booster-core-certification"]["archive-source-commit"]', $workflow );
		self::assertStringContainsString( 'RAN_BOOSTER_CORE_COMMIT', $workflow );
		self::assertStringContainsString( 'RAN_BOOSTER_CORE_ARCHIVE_SOURCE_COMMIT', $workflow );
		self::assertStringContainsString( '.target_commitish == $commit', $workflow );
		self::assertNotSame(
			$this->certification()['commit'],
			$this->certification()['archive_source_commit']
		);
	}

	public function testQualityPinsTheExactReleasedCoreAndCurrentSetupPhpAction(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( '.extra["ran-booster-core-certification"].commit', $workflow );
		self::assertStringContainsString( '.extra["ran-booster-core-certification"].tag', $workflow );
		self::assertStringNotContainsString( $this->certification()['commit'], $workflow );
		self::assertStringContainsString( 'git describe --tags --exact-match HEAD', $workflow );
		self::assertStringContainsString( 'composer validate --strict --no-check-all --no-check-publish', $workflow );
		self::assertStringContainsString( 'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # 2.37.2', $workflow );
	}

	public function testQualityBuildsExactProfileBPromotionEvidence(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertSame( 1, substr_count( $workflow, 'composer build:release -- "$source_commit"' ) );
		self::assertStringContainsString( 'composer verify:release -- "$archive" "$source_commit"', $workflow );
		self::assertStringContainsString( 'schema: "ran-profile-b-promotion"', $workflow );
		self::assertStringContainsString( 'build/ran-profile-b-promotion.json', $workflow );
		self::assertGreaterThanOrEqual( 2, substr_count( $workflow, '--arg quality_commit "$GITHUB_SHA"' ) );
		self::assertStringContainsString( '--arg source_commit "$source_commit"', $workflow );
		self::assertStringContainsString( 'extensions: zip', $workflow );
		$buildStart = strpos( $workflow, '- name: Build and verify exact runtime archive' );
		$uploadStart = strpos( $workflow, '- name: Upload verified runtime evidence' );
		self::assertIsInt( $buildStart );
		self::assertIsInt( $uploadStart );
		$build = substr( $workflow, $buildStart, $uploadStart - $buildStart );
		self::assertStringNotContainsString( 'GH_TOKEN:', $build );
		self::assertStringNotContainsString( 'gh api ', $build );
		self::assertStringContainsString( 'tested_tree="$source_tree"', $build );
		self::assertStringContainsString( 'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a', $workflow );
	}

	public function testQualityCandidateDispatchIsInputFreeAndBoundToCanonicalBotPullRequest(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( "workflow_dispatch:\n  pull_request:", $workflow );
		self::assertStringNotContainsString( 'release_pr:', $workflow );
		self::assertStringNotContainsString( 'release_sha:', $workflow );
		self::assertStringContainsString( "release_branch='release-please--branches--main--components--ran-booster-bitbucket'", $workflow );
		self::assertStringContainsString( '.user.login == $bot', $workflow );
		self::assertStringContainsString( 'test "$source_commit" = "$pr_head_sha"', $workflow );
		self::assertStringContainsString( 'bash scripts/validate-release-candidate.sh "$pr_base_sha" "$pr_head_sha"', $workflow );
		self::assertStringContainsString( 'Release candidate install readback', $workflow );
		self::assertStringContainsString( '! wp plugin is-active ran-booster-bitbucket', $workflow );
		self::assertStringContainsString( 'diff -qr "$expected_root/ran-booster-bitbucket" "$plugin_root"', $workflow );
	}

	public function testReleaseCallerPinsSharedProfileBWithExactLocalInputs(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString( 'workflow_run:', $workflow );
		self::assertStringContainsString( 'permissions: {}', $workflow );
		self::assertStringContainsString(
			'uses: RocketsAreNostalgic/.github/.github/workflows/release-profile-b.yml@cb42ecd841916ebe73f147e5b933cd7bcc392db8',
			$workflow
		);
		self::assertStringContainsString( 'expected-workflow-path: .github/workflows/quality.yml', $workflow );
		self::assertStringContainsString( 'release-pr-head: release-please--branches--main--components--ran-booster-bitbucket', $workflow );
		self::assertStringContainsString( 'artifact-prefix: ran-booster-bitbucket-runtime', $workflow );
		foreach ( array( 'contents: write', 'issues: write', 'pull-requests: write', 'actions: write' ) as $permission ) {
			self::assertStringContainsString( $permission, $workflow );
		}
		self::assertStringNotContainsString( 'steps:', $workflow );
		self::assertStringNotContainsString( 'runs-on:', $workflow );
	}

	public function testReleasePleaseConfigurationUsesDraftProfileBPublication(): void {
		$config = json_decode(
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/release-please-config.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertIsArray( $config );
		self::assertTrue( $config['draft'] ?? false );
		self::assertTrue( $config['force-tag-creation'] ?? false );
		self::assertNotSame( true, $config['skip-github-release'] ?? false );
	}

	public function testReleaseCandidateValidatorBehaviorRemainsPartOfRepositoryCheck(): void {
		$root     = dirname( __DIR__, 2 );
		$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsArray( $composer );
		self::assertSame( 'bash tests/Workflow/validate-release-candidate.sh', $composer['scripts']['test:release-candidate'] ?? null );
		self::assertContains( '@test:release-candidate', $composer['scripts']['check:repository'] ?? array() );
		self::assertFileIsReadable( $root . '/tests/Workflow/validate-release-candidate.sh' );
		self::assertArrayNotHasKey( 'test:release-marker', $composer['scripts'] );
		self::assertArrayNotHasKey( 'test:release-state', $composer['scripts'] );
	}

	public function testWorkflowActionsArePinnedToImmutableCommits(): void {
		foreach ( array( 'quality.yml', 'release-please.yml' ) as $workflowName ) {
			$workflow = $this->workflow( $workflowName );
			self::assertSame( 1, preg_match_all( '/^\s*uses:\s*[^.\s][^@\s]*@([^\s#]+)/m', $workflow, $matches ) > 0 ? 1 : 0 );
			foreach ( $matches[1] as $reference ) {
				self::assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $reference, $workflowName . ' has a mutable action reference.' );
			}
		}
	}

	private function workflow( string $name ): string {
		$workflow = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		return $workflow;
	}

	/** @return array{tag: string, commit: string, archive_source_commit: string} */
	private function certification(): array {
		$composer = json_decode(
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local certification contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertIsArray( $composer );
		$certification = $composer['extra']['ran-booster-core-certification'] ?? null;
		self::assertIsArray( $certification );
		self::assertMatchesRegularExpression( '/^v[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $certification['tag'] ?? '' );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $certification['commit'] ?? '' );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $certification['archive-source-commit'] ?? '' );

		return array(
			'tag'    => (string) $certification['tag'],
			'commit' => (string) $certification['commit'],
			'archive_source_commit' => (string) $certification['archive-source-commit'],
		);
	}
}
