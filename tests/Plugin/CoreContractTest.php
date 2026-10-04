<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Isolated PHPUnit namespace matches the test autoload contract.
namespace Tests\Plugin;

use PHPUnit\Framework\TestCase;

final class CoreContractTest extends TestCase {
	public function test_configured_core_checkout_publishes_the_required_api_generation(): void {
		$core_root          = getenv( 'RAN_BOOSTER_CORE_PATH' );
		$core_root          = false === $core_root || '' === $core_root
			? dirname( __DIR__, 3 ) . '/ran-booster'
			: rtrim( $core_root, '/\\' );
		$entry_file         = $core_root . '/ran-booster.php';
		$documentation_file = $core_root . '/views/documentation.php';
		$core               = file_get_contents( $entry_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local contract fixture.
		$documentation      = file_get_contents( $documentation_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local contract fixture.

		self::assertIsString( $core, 'A RAN Booster entry file is required at ' . $entry_file );
		self::assertIsString( $documentation, 'A RAN Booster documentation view is required at ' . $documentation_file );
		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_PROVIDER_API_VERSION',\\s*14\\s*\\)/",
			$core
		);
		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_ADDON_API_VERSION',\\s*17\\s*\\)/",
			$core
		);
		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION',\\s*3\\s*\\)/",
			$core
		);
		self::assertStringNotContainsString( 'RAN_BOOSTER_LOGGING_API_VERSION', $core );
		self::assertStringContainsString( 'ran_booster_documentation_sections_after_provider_', $documentation );

		$certification = $this->certification();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Isolated CLI contract executes repository tooling in a disposable subprocess, outside WordPress runtime.
		$commit = shell_exec( 'git -C ' . escapeshellarg( $core_root ) . ' rev-parse HEAD' );
		self::assertIsString( $commit );
		if ( 'candidate' === getenv( 'RAN_BOOSTER_CORE_TEST_MODE' ) ) {
			$composer = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local candidate identity.
			self::assertSame( $composer['extra']['ran-booster-core-candidate']['commit'], trim( $commit ) );
		} else {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Isolated CLI contract executes repository tooling in a disposable subprocess, outside WordPress runtime.
			$tag = shell_exec( 'git -C ' . escapeshellarg( $core_root ) . ' describe --tags --exact-match HEAD' );
			self::assertIsString( $tag );
			self::assertSame( $certification['commit'], trim( $commit ) );
			self::assertSame( $certification['tag'], trim( $tag ) );
		}
	}

	public function test_candidate_mode_rejects_an_unrelated_checkout_and_unknown_mode(): void {
		$root = dirname( __DIR__, 2 );
		foreach ( array(
			'candidate' => 'requires the exact configured Core checkout',
			'unknown'   => 'Unsupported Core test mode',
		) as $mode => $message ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Build a PHP literal for a disposable subprocess fixture, not runtime debug output.
			$program = 'require ' . var_export( $root . '/tests/fixtures/certified-core-checkout.php', true ) . '; try { ran_booster_bitbucket_certified_core_root(); echo "unexpected success"; } catch (RuntimeException $error) { echo $error->getMessage(); }';
			$command = 'RAN_BOOSTER_CORE_TEST_MODE=' . escapeshellarg( $mode )
				. ' RAN_BOOSTER_CORE_PATH=' . escapeshellarg( $root )
				. ' ' . escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $program );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Isolated CLI contract executes repository tooling in a disposable subprocess, outside WordPress runtime.
			$output = shell_exec( $command );
			self::assertIsString( $output );
			self::assertStringContainsString( $message, $output );
		}
	}

	public function test_installed_proof_separates_tag_target_from_archive_source(): void {
		$workflow = $this->workflow( 'installed-proof.yml' );

		self::assertStringContainsString( '.extra["ran-booster-core-certification"].commit', $workflow );
		self::assertStringContainsString( '.extra["ran-booster-core-certification"]["archive-source-commit"]', $workflow );
		self::assertStringContainsString( 'RAN_BOOSTER_CORE_COMMIT', $workflow );
		self::assertStringContainsString( 'RAN_BOOSTER_CORE_ARCHIVE_SOURCE_COMMIT', $workflow );
		self::assertStringContainsString( '.target_commitish == $commit', $workflow );
		// Tag and archive source are independent identities, but may legitimately coincide.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local repository or archive bytes in standalone CLI tooling; no remote HTTP request.
		$proof = (string) file_get_contents( dirname( __DIR__ ) . '/WordPress/bitbucket-installed-proof.sh' );
		self::assertStringContainsString( '"$provided_core_commit" == "$expected_core_commit"', $proof );
		self::assertStringContainsString( '"$provided_core_archive_source_commit" == "$expected_core_archive_source_commit"', $proof );
		self::assertStringContainsString( "' \"\$expected_core_tag\" \"\$expected_core_archive_source_commit\"; then", $proof );
		self::assertStringContainsString( '$commit !== ( $document["commit"] ?? null )', $proof );
	}

	public function test_source_qualification_cannot_bypass_installed_release_admission(): void {
		$installed = $this->workflow( 'installed-proof.yml' );
		$release   = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString( "  workflow_call:\n    inputs:\n      source-sha:", $installed );
		self::assertStringContainsString( 'required: true', $installed );
		self::assertStringContainsString( 'workflow_dispatch:', $installed );
		self::assertStringNotContainsString( 'pull_request:', $installed );
		self::assertStringNotContainsString( '  push:', $installed );
		self::assertStringContainsString( 'ref: ${{ inputs.source-sha || github.sha }}', $installed );
		self::assertStringContainsString( 'test "$source_commit" = "$expected_source"', $installed );
		self::assertStringContainsString( '.immutable == true and .draft == false', $installed );
		self::assertStringContainsString( 'run: bash tests/WordPress/bitbucket-installed-proof.sh', $installed );
		self::assertStringContainsString( 'uses: ./.github/workflows/installed-proof.yml', $release );
		self::assertStringContainsString( 'source-sha: ${{ github.event.workflow_run.head_sha }}', $release );
		self::assertStringContainsString( "  release:\n    needs: certified-core\n", $release );
		foreach ( array( "conclusion == 'success'", "event == 'push'", "head_branch == 'main'", "path == '.github/workflows/quality.yml'", 'head_repository.id == github.repository_id', 'head_repository.full_name == github.repository' ) as $guard ) {
			self::assertStringContainsString( 'github.event.workflow_run.' . $guard, $release );
		}
		self::assertStringNotContainsString( 'continue-on-error:', $release . $installed );
		self::assertStringNotContainsString( 'always()', $release );
		self::assertStringNotContainsString( 'workflow_dispatch:', $release );
	}

	public function test_quality_pins_exact_certified_release_without_replacing_installed_proof(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( '.extra["ran-booster-core-certification"].commit', $workflow );
		self::assertStringNotContainsString( 'RAN_BOOSTER_CORE_TEST_MODE: candidate', $workflow );
		self::assertStringNotContainsString( $this->certification()['commit'], $workflow );
		self::assertStringNotContainsString( 'git describe --tags --exact-match HEAD', $workflow );
		self::assertStringContainsString( 'composer validate --strict --no-check-all --no-check-publish', $workflow );
		self::assertStringContainsString( 'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # 2.37.2', $workflow );
	}

	public function test_quality_builds_exact_profile_bpromotion_evidence(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertSame( 1, substr_count( $workflow, 'composer build:release -- "$source_commit"' ) );
		self::assertStringContainsString( 'composer verify:release -- "$archive" "$source_commit"', $workflow );
		self::assertStringContainsString( 'schema: "ran-profile-b-promotion"', $workflow );
		self::assertStringContainsString( 'build/ran-profile-b-promotion.json', $workflow );
		self::assertGreaterThanOrEqual( 2, substr_count( $workflow, '--arg quality_commit "$GITHUB_SHA"' ) );
		self::assertStringContainsString( '--arg source_commit "$source_commit"', $workflow );
		self::assertStringContainsString( 'extensions: zip', $workflow );
		$build_start  = strpos( $workflow, '- name: Build and verify exact runtime archive' );
		$upload_start = strpos( $workflow, '- name: Upload verified runtime evidence' );
		self::assertIsInt( $build_start );
		self::assertIsInt( $upload_start );
		$build = substr( $workflow, $build_start, $upload_start - $build_start );
		self::assertStringNotContainsString( 'GH_TOKEN:', $build );
		self::assertStringNotContainsString( 'gh api ', $build );
		self::assertStringContainsString( 'tested_tree="$source_tree"', $build );
		self::assertStringContainsString( 'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a', $workflow );
	}

	public function test_quality_candidate_dispatch_is_input_free_and_bound_to_canonical_bot_pull_request(): void {
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

	public function test_release_caller_pins_shared_profile_bwith_exact_local_inputs(): void {
		$workflow = $this->workflow( 'release-please.yml' );

		self::assertStringContainsString( 'workflow_run:', $workflow );
		self::assertStringContainsString( 'permissions: {}', $workflow );
		self::assertStringContainsString(
			'uses: RocketsAreNostalgic/.github/.github/workflows/release-profile-b.yml@e2fb19244a301a62f8fae2a80536898adf21fe22',
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

	public function test_release_please_configuration_uses_draft_profile_bpublication(): void {
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

	public function test_release_candidate_validator_behavior_remains_part_of_repository_check(): void {
		$root     = dirname( __DIR__, 2 );
		$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsArray( $composer );
		self::assertSame( 'bash tests/Workflow/validate-release-candidate.sh', $composer['scripts']['test:release-candidate'] ?? null );
		self::assertSame( 'vendor/bin/phpunit --configuration phpunit.xml', $composer['scripts']['test'] ?? null );
		self::assertContains( '@test', $composer['scripts']['check:host'] ?? array() );
		self::assertContains( '@test:release-candidate', $composer['scripts']['check:host'] ?? array() );
		self::assertFileIsReadable( $root . '/tests/Workflow/validate-release-candidate.sh' );
		self::assertArrayNotHasKey( 'test:release-marker', $composer['scripts'] );
		self::assertArrayNotHasKey( 'test:release-state', $composer['scripts'] );
	}

	public function test_workflow_actions_are_pinned_to_immutable_commits(): void {
		foreach ( array( 'quality.yml', 'release-please.yml' ) as $workflow_name ) {
			$workflow = $this->workflow( $workflow_name );
			self::assertSame( 1, preg_match_all( '/^\s*uses:\s*[^.\s][^@\s]*@([^\s#]+)/m', $workflow, $matches ) > 0 ? 1 : 0 );
			foreach ( $matches[1] as $reference ) {
				self::assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $reference, $workflow_name . ' has a mutable action reference.' );
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
			'tag'                   => (string) $certification['tag'],
			'commit'                => (string) $certification['commit'],
			'archive_source_commit' => (string) $certification['archive-source-commit'],
		);
	}
}
