<?php

declare(strict_types=1);

namespace Tests\Plugin;

use PHPUnit\Framework\TestCase;

final class QualityWorkflowFanInContractTest extends TestCase {
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
