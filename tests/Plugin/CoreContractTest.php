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
			"/define\\(\\s*'RAN_BOOSTER_PROVIDER_API_VERSION',\\s*10\\s*\\)/",
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

	public function testQualityPinsTheExactReleasedCoreAndCurrentSetupPhpAction(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( '.extra["ran-booster-core-certification"].commit', $workflow );
		self::assertStringContainsString( '.extra["ran-booster-core-certification"].tag', $workflow );
		self::assertStringNotContainsString( $this->certification()['commit'], $workflow );
		self::assertStringContainsString( 'git describe --tags --exact-match HEAD', $workflow );
		self::assertStringContainsString( 'composer validate --strict --no-check-all --no-check-publish', $workflow );
		self::assertStringContainsString( 'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # 2.37.2', $workflow );
	}

	public function testQualityUsesTrustedTreeAdmissionAndAFullFallback(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertSame( 1, substr_count( $workflow, 'composer build:release -- "$source_commit"' ) );
		self::assertStringContainsString( 'merge_commit_sha == $merge', $workflow );
		self::assertStringContainsString( '.pull_request.tested_tree == $main_tree', $workflow );
		self::assertStringContainsString( '($lane == "release-candidate" and .source_commit == $head_sha', $workflow );
		self::assertStringContainsString( 'or ($lane == "full" and .source_commit == .quality_commit)', $workflow );
		self::assertStringContainsString( 'Exact prior PR evidence was unavailable; running the full fallback lane.', $workflow );
		self::assertStringContainsString( 'steps.admission.outcome != \'success\'', $workflow );
		self::assertStringContainsString( 'schema_version: 3', $workflow );
		self::assertStringContainsString( 'mode: "built"', $workflow );
		self::assertStringContainsString( '.mode = "admitted"', $workflow );
		self::assertStringContainsString( 'workflow_sha', $workflow );
		self::assertStringContainsString( 'actions/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c', $workflow );
		self::assertStringContainsString( 'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a', $workflow );
		self::assertStringContainsString( "composer.json \\\n              composer.lock \\\n              .github/workflows/quality.yml", $workflow );
	}

	public function testReleaseCandidateIsExactBotDispatchedAndMinimallyInstalled(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( 'release_pr:', $workflow );
		self::assertStringContainsString( 'release_sha:', $workflow );
		self::assertStringContainsString( "GITHUB_ACTOR\" == 'github-actions[bot]'", $workflow );
		self::assertStringContainsString( "GITHUB_TRIGGERING_ACTOR\" == 'github-actions[bot]'", $workflow );
		self::assertStringContainsString( 'test "$RAN_DISPATCH_RELEASE_SHA" = "$pr_head_sha"', $workflow );
		self::assertStringContainsString( 'test "$GITHUB_SHA" = "$pr_head_sha"', $workflow );
		self::assertStringContainsString( 'scripts/validate-release-candidate.sh', $workflow );
		self::assertStringContainsString( 'source_commit="$pr_head_sha"', $workflow );
		self::assertStringContainsString( 'Release candidate install readback', $workflow );
		self::assertStringContainsString( 'wp plugin install "$GITHUB_WORKSPACE/build/ran-booster-bitbucket-', $workflow );
		self::assertStringContainsString( '! wp plugin is-active ran-booster-bitbucket', $workflow );
		self::assertStringContainsString( 'diff -qr "$expected_root/ran-booster-bitbucket" "$plugin_root"', $workflow );
		self::assertStringNotContainsString( 'RAN_BOOSTER_CORE_READ_SSH_KEY', substr( $workflow, (int) strpos( $workflow, 'release-candidate-install:' ) ) );
	}

	public function testReleaseUsesMainAdmissionArtifactWithoutParentTopology(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString( 'workflow_run:', $workflow );
		self::assertStringContainsString( "github.event.workflow_run.event == 'push'", $workflow );
		self::assertStringContainsString( 'actions: write', $workflow );
		self::assertStringContainsString( 'merge_commit_sha == $merge', $workflow );
		self::assertStringContainsString( 'test "$main_tree" = "$head_tree"', $workflow );
		self::assertStringNotContainsString( '^2', $workflow );
		self::assertStringNotContainsString( 'rev-list --parents', $workflow );
		self::assertStringContainsString( 'run-id: ${{ github.event.workflow_run.id }}', $workflow );
		self::assertStringContainsString( 'ran-booster-bitbucket-runtime-${{ github.event.workflow_run.id }}-${{ github.event.workflow_run.run_attempt }}', $workflow );
		self::assertStringNotContainsString( 'composer build:release', $workflow );
		self::assertStringNotContainsString( 'setup-php@', $workflow );
	}

	public function testReleaseDispatchesAndProvesExactBotCandidateBeforePublishing(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString( 'RAN_RELEASE_PRS: ${{ steps.release-please.outputs.prs }}', $workflow );
		self::assertStringContainsString( 'RAN_RELEASE_PRS_CREATED: ${{ steps.release-please.outputs.prs_created }}', $workflow );
		self::assertStringContainsString( 'Expected exactly one Release Please action output pull request.', $workflow );
		self::assertStringNotContainsString( '.sha | type == "string" and test("^[0-9a-f]{40}$")', $workflow );
		self::assertStringContainsString( '(.files | type) == "array"', $workflow );
		self::assertStringContainsString( '(.files | length) == 0', $workflow );
		self::assertStringContainsString( '-f "release_sha=${head_sha}"', $workflow );
		self::assertStringContainsString( '.actor.login == $bot', $workflow );
		self::assertStringContainsString( '.triggering_actor.login == $bot', $workflow );
		self::assertStringContainsString( 'No exact successful bot candidate run exists.', $workflow );
		self::assertStringContainsString( '.admission.main_commit == $main_commit', $workflow );
		self::assertStringContainsString( '.pull_request.head_sha == $head_commit', $workflow );
		self::assertStringContainsString( 'candidate_workflow_hash', $workflow );
		self::assertStringContainsString( '--target "$RAN_RELEASE_COMMIT"', $workflow );
		self::assertStringContainsString( 'RAN_IMMUTABLE_RELEASES_ENABLED', $workflow );

		$preflight = strpos( $workflow, '- name: Prove exact merged lifecycle before mutation' );
		$download  = strpos( $workflow, '- name: Download exact archive admitted by main Quality' );
		$verify    = strpos( $workflow, '- name: Verify release identity and exact artifact provenance' );
		$draft     = strpos( $workflow, '- name: Create or reuse draft and attach verified assets' );
		$publish   = strpos( $workflow, '- name: Publish only under immutable-release contract' );
		$readback  = strpos( $workflow, '- name: Read back immutable release and reconcile exact PR' );

		self::assertIsInt( $preflight );
		self::assertIsInt( $download );
		self::assertIsInt( $verify );
		self::assertIsInt( $draft );
		self::assertIsInt( $publish );
		self::assertIsInt( $readback );
		self::assertTrue( $preflight < $download );
		self::assertTrue( $download < $verify );
		self::assertTrue( $verify < $draft );
		self::assertTrue( $draft < $publish );
		self::assertTrue( $publish < $readback );
	}

	public function testReleaseCandidateMarkerSupportsSafeUnchangedPullRequestRetry(): void {
		$workflow = $this->workflow( 'release-please.yml' );
		$start    = strpos( $workflow, '- name: Validate and dispatch exact Release Please candidate' );
		$end      = strpos( $workflow, '- name: Download exact archive admitted by main Quality' );
		self::assertIsInt( $start );
		self::assertIsInt( $end );
		$dispatch = substr( $workflow, $start, $end - $start );

		self::assertStringContainsString( 'if [[ "$RAN_RELEASE_PRS_CREATED" == true ]]', $dispatch );
		self::assertStringContainsString( 'elif [[ -n "$RAN_RELEASE_PRS_CREATED" && "$RAN_RELEASE_PRS_CREATED" != false ]]', $dispatch );
		self::assertStringContainsString( "marker_prefix='<!-- ran-booster-bitbucket-release-candidate:'", $dispatch );
		self::assertStringContainsString( 'Expected exactly one current bot-authored release candidate marker.', $dispatch );
		self::assertStringContainsString( 'commits(last: 1)', $dispatch );
		self::assertStringContainsString( 'bash scripts/reconcile-release-candidate-marker.sh', $dispatch );
		self::assertStringContainsString( 'repos/${GITHUB_REPOSITORY}/issues/${pr_number}/comments', $dispatch );
		self::assertStringContainsString( 'repos/${GITHUB_REPOSITORY}/issues/comments/${marker_id}', $dispatch );
		self::assertStringContainsString( 'bash scripts/has-trusted-release-candidate-run.sh', $dispatch );

		$validator = strpos( $dispatch, 'bash scripts/validate-release-candidate.sh "$base_sha" "$head_sha"' );
		$recovery  = strpos( $dispatch, 'commit_identity="$(gh api graphql' );
		self::assertIsInt( $validator );
		self::assertIsInt( $recovery );
		self::assertTrue( $validator < $recovery );

		$reconciler = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/reconcile-release-candidate-marker.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $reconciler );
		self::assertStringContainsString( 'expected at most one bot-authored release candidate marker.', $reconciler );
		self::assertStringContainsString( '.signature.isValid == true', $reconciler );
		self::assertStringContainsString( '.signature.state == "VALID"', $reconciler );
		self::assertStringContainsString( '.signature.signer.login == $signer', $reconciler );
		self::assertStringContainsString( '.author.user.login == $bot', $reconciler );
		self::assertStringContainsString( '41898282+github-actions[bot]@users.noreply.github.com', $reconciler );
		self::assertStringContainsString( '.committer.email == $committer_email', $reconciler );
		self::assertStringContainsString( '{operation: "patch", comment_id: $comment_id, marker: $marker}', $reconciler );

		$run_selector = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/has-trusted-release-candidate-run.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $run_selector );
		self::assertStringContainsString( '.actor.login == $bot', $run_selector );
		self::assertStringContainsString( '.triggering_actor.login == $bot', $run_selector );
		self::assertStringContainsString( '.status == "queued"', $run_selector );
		self::assertStringContainsString( '.status == "in_progress"', $run_selector );
		self::assertStringContainsString( '.status == "completed" and .conclusion == "success"', $run_selector );
		self::assertStringNotContainsString( '.conclusion == "failure"', $run_selector );
		self::assertStringNotContainsString( '.conclusion == "cancelled"', $run_selector );
	}

	public function testReleaseCandidateValidatorBehaviorIsPartOfComposerCheck(): void {
		$root     = dirname( __DIR__, 2 );
		$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsArray( $composer );
		self::assertSame( 'bash tests/Workflow/validate-release-candidate.sh', $composer['scripts']['test:release-candidate'] ?? null );
		self::assertContains( '@test:release-candidate', $composer['scripts']['check'] ?? array() );
		self::assertFileIsReadable( $root . '/tests/Workflow/validate-release-candidate.sh' );
		self::assertSame( 'bash tests/Workflow/reconcile-release-candidate-marker.sh', $composer['scripts']['test:release-marker'] ?? null );
		self::assertContains( '@test:release-marker', $composer['scripts']['check'] ?? array() );
		self::assertFileIsReadable( $root . '/tests/Workflow/reconcile-release-candidate-marker.sh' );
		self::assertSame( 'bash tests/Workflow/release-state-contracts.sh', $composer['scripts']['test:release-state'] ?? null );
		self::assertContains( '@test:release-state', $composer['scripts']['check'] ?? array() );
		self::assertFileIsReadable( $root . '/tests/Workflow/release-state-contracts.sh' );
	}

	public function testReleaseTagIsPeeledAndProvedAtEveryMutationBoundary(): void {
		$workflow = $this->workflow( 'release-please.yml' );
		self::assertSame( 4, substr_count( $workflow, 'bash scripts/verify-release-tag-target.sh' ) );
		self::assertStringNotContainsString( '.object.type == "commit" and .object.sha == $commit', $workflow );

		$draft_start    = strpos( $workflow, '- name: Create or reuse draft and attach verified assets' );
		$publish_start  = strpos( $workflow, '- name: Publish only under immutable-release contract' );
		$readback_start = strpos( $workflow, '- name: Read back immutable release and reconcile exact PR' );
		self::assertIsInt( $draft_start );
		self::assertIsInt( $publish_start );
		self::assertIsInt( $readback_start );
		$draft = substr( $workflow, $draft_start, $publish_start - $draft_start );
		self::assertSame( 2, substr_count( $draft, 'bash scripts/verify-release-tag-target.sh' ) );
		self::assertTrue( strpos( $draft, 'verify-release-tag-target.sh' ) < strpos( $draft, 'gh release create' ) );
		self::assertTrue( strrpos( $draft, 'verify-release-tag-target.sh' ) < strpos( $draft, 'gh release upload' ) );
		self::assertTrue( strpos( $workflow, 'verify-release-tag-target.sh', $publish_start ) < strpos( $workflow, 'gh release edit', $publish_start ) );
		self::assertTrue( strpos( $workflow, 'verify-release-tag-target.sh', $readback_start ) < strpos( $workflow, 'labels="$(gh api', $readback_start ) );

		$verifier = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/verify-release-tag-target.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $verifier );
		self::assertStringContainsString( 'git/tags/${target_sha}', $verifier );
		self::assertStringContainsString( 'tag resolves to a different release commit.', $verifier );
	}

	public function testImmutableReleaseReadbackProvesExactRemoteAssetBytesBeforeLabels(): void {
		$workflow = $this->workflow( 'release-please.yml' );
		self::assertSame( 2, substr_count( $workflow, 'bash scripts/verify-immutable-release-assets.sh' ) );
		$draft_start   = strpos( $workflow, '- name: Create or reuse draft and attach verified assets' );
		$publish_start = strpos( $workflow, '- name: Publish only under immutable-release contract' );
		self::assertIsInt( $draft_start );
		self::assertIsInt( $publish_start );
		$draft = substr( $workflow, $draft_start, $publish_start - $draft_start );
		$upload = strpos( $draft, 'gh release upload' );
		$proof  = strpos( $draft, 'bash scripts/verify-immutable-release-assets.sh' );
		self::assertIsInt( $upload );
		self::assertIsInt( $proof );
		self::assertTrue( $upload < $proof );
		self::assertStringContainsString( 'draft_release_json="$(gh api', $draft );

		$start    = strpos( $workflow, '- name: Read back immutable release and reconcile exact PR' );
		self::assertIsInt( $start );
		$readback = substr( $workflow, $start );

		self::assertStringContainsString( "if: steps.release-state.outputs.release-required == 'true'", $readback );
		self::assertStringContainsString( 'immutable_assets="$(mktemp -d)"', $readback );
		self::assertStringContainsString( '--pattern "$archive_name"', $readback );
		self::assertStringContainsString( '--pattern "$checksum_name"', $readback );
		self::assertStringContainsString( 'bash scripts/verify-immutable-release-assets.sh', $readback );

		$assetProof = strpos( $readback, 'bash scripts/verify-immutable-release-assets.sh' );
		$labels     = strpos( $readback, 'labels="$(gh api' );
		self::assertIsInt( $assetProof );
		self::assertIsInt( $labels );
		self::assertTrue( $assetProof < $labels );

		$verifier = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/verify-immutable-release-assets.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $verifier );
		self::assertStringContainsString( '([.assets[].name] | sort) == ([$archive, $checksum] | sort)', $verifier );
		self::assertStringContainsString( 'cmp -s "$archive" "$download_directory/$archive_name"', $verifier );
		self::assertStringContainsString( 'cmp -s "$checksum" "$download_directory/$checksum_name"', $verifier );
	}

	public function testPackageReleaseCommandsDoNotDependOnGitWorkingDirectory(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString( 'gh release create "$RAN_RELEASE_TAG" --draft --target "$RAN_RELEASE_COMMIT" --title "$RAN_RELEASE_TAG" --generate-notes "${prerelease[@]}" --repo "$GITHUB_REPOSITORY"', $workflow );
		self::assertSame( 2, substr_count( $workflow, '--pattern "$archive_name"' ) );
		self::assertSame( 2, substr_count( $workflow, '--pattern "$checksum_name"' ) );
		self::assertStringContainsString( 'gh release edit "$RAN_RELEASE_TAG" --draft=false "${latest[@]}" --repo "$GITHUB_REPOSITORY"', $workflow );
		self::assertSame( 7, substr_count( $workflow, '--repo "$GITHUB_REPOSITORY"' ) );
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
