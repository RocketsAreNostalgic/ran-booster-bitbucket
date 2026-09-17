<?php

declare(strict_types=1);

namespace Tests\Plugin;

use PHPUnit\Framework\TestCase;

final class QualityWorkflowFanInContractTest extends TestCase {
	public function testSharedPhpProviderContractIsPinnedToReviewedProfile(): void {
		$root     = dirname( __DIR__, 2 );
		$workflow = file_get_contents( $root . '/.github/workflows/quality.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		$baselineStart   = strpos( $workflow, "  baseline:\n" );
		$repositoryStart = strpos( $workflow, "  repository-quality:\n" );
		self::assertIsInt( $baselineStart );
		self::assertIsInt( $repositoryStart );
		self::assertTrue( $baselineStart < $repositoryStart );
		$baseline = substr( $workflow, $baselineStart, $repositoryStart - $baselineStart );

		self::assertStringContainsString(
			'uses: RocketsAreNostalgic/.github/.github/workflows/quality-php-library-v2.yml@788f783d2998994f7aab9691710911ed1bd762c9',
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
		$extensionFloor = $composer['extra']['ran-booster-extension']['requires-php'] ?? null;
		self::assertIsString( $extensionFloor );
		self::assertSame( '^' . $extensionFloor, $composer['require']['php'] ?? null );

		$plugin = file_get_contents( $root . '/ran-booster-bitbucket.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $plugin );
		$matched = preg_match( '/^[ \t]*\*[ \t]+Requires PHP:[ \t]*([^\r\n]+)/m', $plugin, $matches );
		self::assertSame( 1, $matched );
		self::assertSame( $extensionFloor, trim( $matches[1] ) );
		self::assertStringContainsString( "php-floor: '{$extensionFloor}'", $baseline );
	}

	public function testComposerQualityAggregatesPreserveSourceAndRepositoryEvidence(): void {
		$composer = json_decode(
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertIsArray( $composer );

		self::assertSame(
			array(
				'@lint:php',
				'@lint:syntax',
			),
			$composer['scripts']['check'] ?? null
		);
		self::assertSame(
			array(
				'@test:unit',
				'@test:release-candidate',
				'@test:release-marker',
				'@test:release-state',
				'@check',
			),
			$composer['scripts']['check:repository'] ?? null
		);
	}

	public function testRepositoryQualityLaneRunsTheRepositoryAggregate(): void {
		$workflow = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/quality.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		$repositoryStart = strpos( $workflow, "  repository-quality:\n" );
		$releaseStart    = strpos( $workflow, "  release-candidate-install:\n" );
		self::assertIsInt( $repositoryStart );
		self::assertIsInt( $releaseStart );
		self::assertTrue( $repositoryStart < $releaseStart );

		$repository = substr( $workflow, $repositoryStart, $releaseStart - $repositoryStart );
		self::assertStringContainsString( 'run: composer check:repository', $repository );
		self::assertStringNotContainsString( "run: composer check\n", $repository );
	}

	public function testTerminalQualityGateRequiresApplicableEvidenceForEveryLane(): void {
		$workflow = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/quality.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		$terminalStart = strpos( $workflow, "  quality:\n    name: Quality\n" );
		self::assertIsInt( $terminalStart );
		$terminal = substr( $workflow, $terminalStart );

		self::assertStringContainsString( 'if: ${{ always() }}', $terminal );
		self::assertStringContainsString( '- runtime-archive', $terminal );
		self::assertStringContainsString( '- baseline', $terminal );
		self::assertStringContainsString( '- repository-quality', $terminal );
		self::assertStringContainsString( '- release-candidate-install', $terminal );
		self::assertStringContainsString( 'RUNTIME_ARCHIVE_RESULT: ${{ needs.runtime-archive.result }}', $terminal );
		self::assertStringContainsString( 'BASELINE_RESULT: ${{ needs.baseline.result }}', $terminal );
		self::assertStringContainsString( 'REPOSITORY_QUALITY_RESULT: ${{ needs.repository-quality.result }}', $terminal );
		self::assertStringContainsString( 'RELEASE_CANDIDATE_INSTALL_RESULT: ${{ needs.release-candidate-install.result }}', $terminal );
		self::assertStringContainsString( 'set -euo pipefail', $terminal );
		self::assertStringContainsString( 'test "$RUNTIME_ARCHIVE_RESULT" = success', $terminal );
		self::assertStringContainsString( 'test "$BASELINE_RESULT" = success', $terminal );

		$admittedStart         = strpos( $terminal, 'if [[ "$ADMITTED" == true ]]' );
		$fullStart             = strpos( $terminal, 'elif [[ "$LANE" == full ]]' );
		$releaseCandidateStart = strpos( $terminal, 'elif [[ "$LANE" == release-candidate ]]' );
		$unsupportedStart      = strpos( $terminal, 'else', $releaseCandidateStart );
		self::assertIsInt( $admittedStart );
		self::assertIsInt( $fullStart );
		self::assertIsInt( $releaseCandidateStart );
		self::assertIsInt( $unsupportedStart );
		self::assertTrue( $admittedStart < $fullStart );
		self::assertTrue( $fullStart < $releaseCandidateStart );
		self::assertTrue( $releaseCandidateStart < $unsupportedStart );

		$admitted = substr( $terminal, $admittedStart, $fullStart - $admittedStart );
		self::assertStringContainsString( 'test "$REPOSITORY_QUALITY_RESULT" = skipped', $admitted );
		self::assertStringContainsString( 'test "$RELEASE_CANDIDATE_INSTALL_RESULT" = skipped', $admitted );

		$full = substr( $terminal, $fullStart, $releaseCandidateStart - $fullStart );
		self::assertStringContainsString( 'test "$REPOSITORY_QUALITY_RESULT" = success', $full );
		self::assertStringContainsString( 'test "$RELEASE_CANDIDATE_INSTALL_RESULT" = skipped', $full );

		$releaseCandidate = substr( $terminal, $releaseCandidateStart, $unsupportedStart - $releaseCandidateStart );
		self::assertStringContainsString( 'test "$REPOSITORY_QUALITY_RESULT" = skipped', $releaseCandidate );
		self::assertStringContainsString( 'test "$RELEASE_CANDIDATE_INSTALL_RESULT" = success', $releaseCandidate );

		$unsupported = substr( $terminal, $unsupportedStart );
		self::assertStringContainsString( 'Unsupported quality lane: $LANE', $unsupported );
		self::assertStringContainsString( 'exit 1', $unsupported );
	}
}
