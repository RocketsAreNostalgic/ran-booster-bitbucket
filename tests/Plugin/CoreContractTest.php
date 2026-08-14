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
			"/define\\(\\s*'RAN_BOOSTER_PROVIDER_API_VERSION',\\s*9\\s*\\)/",
			$core
		);
		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_ADDON_API_VERSION',\\s*15\\s*\\)/",
			$core
		);
		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION',\\s*2\\s*\\)/",
			$core
		);
		self::assertStringNotContainsString( 'RAN_BOOSTER_LOGGING_API_VERSION', $core );
		self::assertStringContainsString( 'ran_booster_documentation_sections_after_provider_', $documentation );

		$certification = $this->certification();
		$commit = shell_exec( 'git -C ' . escapeshellarg( $coreRoot ) . ' rev-parse HEAD' );
		$tag    = shell_exec( 'git -C ' . escapeshellarg( $coreRoot ) . ' describe --tags --exact-match HEAD' );
		self::assertIsString( $commit );
		self::assertIsString( $tag );
		self::assertSame( $certification['commit'], trim( $commit ) );
		self::assertSame( $certification['tag'], trim( $tag ) );
	}

	public function testQualityPinsTheExactReleasedCoreAndCurrentSetupPhpAction(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( '.extra["ran-booster-core-certification"].commit', $workflow );
		self::assertStringContainsString( '.extra["ran-booster-core-certification"].tag', $workflow );
		self::assertStringNotContainsString( $this->certification()['commit'], $workflow );
		self::assertStringContainsString( 'fetch-depth: 0', $workflow );
		self::assertStringContainsString( 'git describe --tags --exact-match HEAD', $workflow );
		self::assertStringContainsString( '^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$', $workflow );
		self::assertStringContainsString( 'composer validate --strict --no-check-all --no-check-publish', $workflow );
		self::assertStringContainsString( 'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # 2.37.2', $workflow );
	}

	public function testQualityBuildsAndSharesOneVerifiedArchive(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertSame( 1, substr_count( $workflow, 'composer build:release -- "$source_commit"' ) );
		self::assertStringContainsString( 'release-please--branches--main--components--ran-booster-bitbucket', $workflow );
		self::assertStringContainsString( 'RAN_PR_HEAD_REPOSITORY', $workflow );
		self::assertStringContainsString( 'schema: "ran-booster-bitbucket-ci-runtime"', $workflow );
		self::assertStringContainsString( 'core_commit: $core_commit', $workflow );
		self::assertStringContainsString( 'core_tag: $core_tag', $workflow );
		self::assertStringContainsString( 'ran-booster-bitbucket-runtime-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}', $workflow );
		self::assertStringContainsString( 'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a', $workflow );
		self::assertStringContainsString( 'actions/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c', $workflow );
		self::assertStringContainsString( 'needs: runtime-archive', $workflow );
		self::assertStringContainsString( "needs.runtime-archive.outputs.release-candidate != 'true'", $workflow );
		self::assertStringContainsString( 'needs.runtime-archive.outputs.archive-sha256', $workflow );
		self::assertStringContainsString( 'sha256sum --check --strict', $workflow );
		self::assertStringContainsString( 'composer verify:release -- "$archive"', $workflow );
	}

	public function testReleaseWaitsForSuccessfulMainPushQualityAndUsesItsArtifact(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString( 'workflow_run:', $workflow );
		self::assertStringContainsString( '- Quality', $workflow );
		self::assertStringContainsString( "github.event.workflow_run.event == 'push'", $workflow );
		self::assertStringContainsString( "github.event.workflow_run.conclusion == 'success'", $workflow );
		self::assertStringContainsString( "github.event.workflow_run.head_branch == 'main'", $workflow );
		self::assertStringContainsString( 'github.event.workflow_run.head_repository.full_name == github.repository', $workflow );
		self::assertStringContainsString( 'actions: read', $workflow );
		self::assertStringContainsString( 'ref: ${{ github.event.workflow_run.head_sha }}', $workflow );
		self::assertStringContainsString( 'run-id: ${{ github.event.workflow_run.id }}', $workflow );
		self::assertStringContainsString( 'ran-booster-bitbucket-runtime-${{ github.event.workflow_run.id }}-${{ github.event.workflow_run.run_attempt }}', $workflow );
		self::assertStringContainsString( 'skip-github-release: true', $workflow );
		self::assertStringNotContainsString( 'package-release:', $workflow );
		self::assertStringNotContainsString( 'composer build:release', $workflow );
		self::assertStringNotContainsString( 'setup-php@', $workflow );
		self::assertStringNotContainsString( 'RAN_BOOSTER_CORE_READ_SSH_KEY', $workflow );
		self::assertStringContainsString( 'git show "${RAN_RELEASE_COMMIT}:composer.json"', $workflow );
		self::assertStringNotContainsString( $this->certification()['commit'], $workflow );
	}

	public function testReleaseProvesTheExactMergedPullRequestBeforePublishing(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString( 'git rev-parse "${RAN_QUALITY_COMMIT}^2"', $workflow );
		self::assertStringContainsString( 'commits/${release_commit}/pulls', $workflow );
		self::assertStringContainsString( '.head.sha == $release', $workflow );
		self::assertStringContainsString( '.merge_commit_sha == $quality', $workflow );
		self::assertStringContainsString( '.head.ref == $head', $workflow );
		self::assertStringContainsString( '.head.repo.full_name == $repository', $workflow );
		self::assertStringContainsString( '.user.login == $bot', $workflow );
		self::assertStringContainsString( 'autorelease: pending', $workflow );
		self::assertStringContainsString( 'autorelease: tagged', $workflow );
		self::assertStringContainsString( '--target "$RAN_RELEASE_COMMIT"', $workflow );
		self::assertStringContainsString( 'RAN_IMMUTABLE_RELEASES_ENABLED', $workflow );

		$preflight = strpos( $workflow, '- name: Prove an exact merged Release Please candidate before publication' );
		$download  = strpos( $workflow, '- name: Download the exact archive tested by Quality' );
		$draft     = strpos( $workflow, '- name: Create or reuse the draft and attach verified assets' );
		$publish   = strpos( $workflow, '- name: Publish only under the immutable-release contract' );
		$readback  = strpos( $workflow, '- name: Read back the immutable release and reconcile its exact PR' );

		self::assertIsInt( $preflight );
		self::assertIsInt( $download );
		self::assertIsInt( $draft );
		self::assertIsInt( $publish );
		self::assertIsInt( $readback );
		self::assertTrue( $preflight < $download );
		self::assertTrue( $download < $draft );
		self::assertTrue( $draft < $publish );
		self::assertTrue( $publish < $readback );
	}

	public function testPackageReleaseCommandsDoNotDependOnTheGitWorkingDirectory(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString( 'gh release create "$RAN_RELEASE_TAG" --draft --target "$RAN_RELEASE_COMMIT" --title "$RAN_RELEASE_TAG" --generate-notes "${prerelease[@]}" --repo "$GITHUB_REPOSITORY"', $workflow );
		self::assertStringContainsString( 'gh release download "$RAN_RELEASE_TAG" --dir "$remote" --pattern "ran-booster-bitbucket-${RAN_RELEASE_VERSION}.zip*" --repo "$GITHUB_REPOSITORY"', $workflow );
		self::assertStringContainsString( 'gh release edit "$RAN_RELEASE_TAG" --draft=false "${latest[@]}" --repo "$GITHUB_REPOSITORY"', $workflow );
		self::assertSame( 4, substr_count( $workflow, '--repo "$GITHUB_REPOSITORY"' ) );
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

	/** @return array{tag: string, commit: string} */
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

		return array(
			'tag'    => (string) $certification['tag'],
			'commit' => (string) $certification['commit'],
		);
	}
}
