<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket\Tests\Plugin;

use PHPUnit\Framework\TestCase;

final class QualityWorkflowFanInContractTest extends TestCase {
	public function test_shared_php_provider_contract_is_pinned_to_reviewed_profile(): void {
		$root     = dirname( __DIR__, 2 );
		$workflow = file_get_contents( $root . '/.github/workflows/quality.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		$baseline_start   = strpos( $workflow, "  baseline:\n" );
		$repository_start = strpos( $workflow, "  repository-quality:\n" );
		self::assertIsInt( $baseline_start );
		self::assertIsInt( $repository_start );
		self::assertTrue( $baseline_start < $repository_start );
		$baseline = substr( $workflow, $baseline_start, $repository_start - $baseline_start );

		self::assertStringContainsString(
			'uses: RocketsAreNostalgic/.github/.github/workflows/quality-php-library-v2.yml@84dde4704058d71646f56c369981bb6aaf201e24',
			$baseline
		);
		self::assertStringContainsString( "php-current: '8.5'", $baseline );
		self::assertStringContainsString( 'php-extensions: zip', $baseline );
		self::assertStringContainsString( "node-version: ''", $baseline );

		$composer = json_decode(
			(string) file_get_contents( $root . '/composer.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertIsArray( $composer );
		$extension_floor = $composer['extra']['ran-booster-extension']['requires-php'] ?? null;
		self::assertIsString( $extension_floor );
		self::assertSame( '^' . $extension_floor, $composer['require']['php'] ?? null );

		$plugin = file_get_contents( $root . '/ran-booster-bitbucket.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $plugin );
		$matched = preg_match( '/^[ \t]*\*[ \t]+Requires PHP:[ \t]*([^\r\n]+)/m', $plugin, $matches );
		self::assertSame( 1, $matched );
		self::assertSame( $extension_floor, trim( $matches[1] ) );
		self::assertStringContainsString( "php-floor: '{$extension_floor}'", $baseline );
	}

	public function test_composer_quality_aggregates_preserve_source_and_repository_evidence(): void {
		$composer = json_decode(
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertIsArray( $composer );

		self::assertSame(
			array(
				'@check:coverage',
				'@standards',
				'@lint:syntax',
				'@test:syntax',
				'@test:coverage',
				'@test:naming',
			),
			$composer['scripts']['check'] ?? null
		);
		self::assertSame(
			array(
				'@analyze',
				'@analyze:development',
				'@test:development-analysis',
				'@test',
				'@test:release-candidate',
				'@check',
			),
			$composer['scripts']['check:host'] ?? null
		);
	}

	public function test_repository_quality_lane_runs_the_repository_aggregate(): void {
		$workflow = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/quality.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		$repository_start = strpos( $workflow, "  repository-quality:\n" );
		$release_start    = strpos( $workflow, "  release-candidate-install:\n" );
		self::assertIsInt( $repository_start );
		self::assertIsInt( $release_start );
		self::assertTrue( $repository_start < $release_start );

		$repository = substr( $workflow, $repository_start, $release_start - $repository_start );
		self::assertStringContainsString( 'run: composer check:host', $repository );
		self::assertStringNotContainsString( 'continue-on-error:', $repository );
		self::assertStringNotContainsString( "run: composer check\n", $repository );
	}

	public function test_terminal_quality_gate_requires_applicable_evidence_for_every_lane(): void {
		$workflow = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/quality.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		$terminal_start = strpos( $workflow, "  quality:\n    name: Quality\n" );
		self::assertIsInt( $terminal_start );
		$terminal = substr( $workflow, $terminal_start );

		self::assertStringContainsString( 'if: ${{ always() }}', $terminal );
		foreach ( array( 'runtime-archive', 'baseline', 'repository-quality', 'release-candidate-install' ) as $need ) {
			self::assertStringContainsString( '- ' . $need, $terminal );
		}
		self::assertStringContainsString( 'test "$RUNTIME_ARCHIVE_RESULT" = success', $terminal );
		self::assertStringContainsString( 'test "$BASELINE_RESULT" = success', $terminal );
		self::assertStringNotContainsString( 'ADMITTED', $terminal );

		$full_start              = strpos( $terminal, 'if [[ "$LANE" == full ]]' );
		$release_candidate_start = strpos( $terminal, 'elif [[ "$LANE" == release-candidate ]]' );
		$unsupported_start       = strpos( $terminal, 'else', $release_candidate_start );
		self::assertIsInt( $full_start );
		self::assertIsInt( $release_candidate_start );
		self::assertIsInt( $unsupported_start );
		self::assertTrue( $full_start < $release_candidate_start );
		self::assertTrue( $release_candidate_start < $unsupported_start );

		$full = substr( $terminal, $full_start, $release_candidate_start - $full_start );
		self::assertStringContainsString( 'test "$REPOSITORY_QUALITY_RESULT" = success', $full );
		self::assertStringContainsString( 'test "$RELEASE_CANDIDATE_INSTALL_RESULT" = skipped', $full );

		$release_candidate = substr( $terminal, $release_candidate_start, $unsupported_start - $release_candidate_start );
		self::assertStringContainsString( 'test "$REPOSITORY_QUALITY_RESULT" = skipped', $release_candidate );
		self::assertStringContainsString( 'test "$RELEASE_CANDIDATE_INSTALL_RESULT" = success', $release_candidate );

		$unsupported = substr( $terminal, $unsupported_start );
		self::assertStringContainsString( 'Unsupported quality lane: $LANE', $unsupported );
		self::assertStringContainsString( 'exit 1', $unsupported );
	}

	public function test_profile_b_release_caller_and_promotion_manifest_are_pinned(): void {
		$root    = dirname( __DIR__, 2 );
		$release = (string) file_get_contents( $root . '/.github/workflows/release-please.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		$quality = (string) file_get_contents( $root . '/.github/workflows/quality.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		$config  = json_decode( (string) file_get_contents( $root . '/release-please-config.json' ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.

		self::assertStringContainsString( 'uses: RocketsAreNostalgic/.github/.github/workflows/release-profile-b.yml@e2fb19244a301a62f8fae2a80536898adf21fe22', $release );
		self::assertStringContainsString( 'expected-workflow-path: .github/workflows/quality.yml', $release );
		self::assertStringContainsString( 'artifact-prefix: ran-booster-bitbucket-runtime', $release );
		self::assertStringContainsString( 'release-pr-head: release-please--branches--main--components--ran-booster-bitbucket', $release );
		self::assertStringContainsString( "workflow_dispatch:\n  pull_request:", $quality );
		self::assertStringNotContainsString( 'inputs:', $quality );
		self::assertStringContainsString( 'schema: "ran-profile-b-promotion"', $quality );
		self::assertStringContainsString( 'build/ran-profile-b-promotion.json', $quality );
		self::assertTrue( $config['draft'] ?? false );
		self::assertTrue( $config['force-tag-creation'] ?? false );
		self::assertNotSame( true, $config['skip-github-release'] ?? false );
	}
}
