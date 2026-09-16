<?php

declare(strict_types=1);

namespace Tests\Plugin;

use PHPUnit\Framework\TestCase;

final class QualityWorkflowFanInContractTest extends TestCase {
	public function testSharedPhpProviderContractIsPinnedToReviewedProfile(): void {
		$workflow = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/quality.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		self::assertStringContainsString(
			'uses: RocketsAreNostalgic/.github/.github/workflows/quality-php-library-v2.yml@788f783d2998994f7aab9691710911ed1bd762c9',
			$workflow
		);
		self::assertStringContainsString( "php-floor: '8.2'", $workflow );
		self::assertStringContainsString( "php-current: '8.5'", $workflow );
		self::assertStringContainsString( 'php-extensions: zip', $workflow );
		self::assertStringContainsString( "node-version: ''", $workflow );
	}

	public function testSourceOnlyComposerCheckExcludesRepositoryOnlyEvidence(): void {
		$composer = json_decode(
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertIsArray( $composer );

		$sourceCheck = $composer['scripts']['check'] ?? array();
		self::assertIsArray( $sourceCheck );
		self::assertNotContains( '@test:release-candidate', $sourceCheck );
		self::assertNotContains( '@test:release-marker', $sourceCheck );
		self::assertNotContains( '@test:release-state', $sourceCheck );
		self::assertNotContains( '@test:unit', $sourceCheck );
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
