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
				'@standards',
				'@lint:syntax',
				'@test',
			),
			$composer['scripts']['check'] ?? null
		);
		self::assertSame(
			array(
				'@test',
				'@check',
			),
			$composer['scripts']['check:host'] ?? null
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
		self::assertStringContainsString( 'run: composer check:host', $repository );
		self::assertStringNotContainsString( "run: composer check\n", $repository );
	}

	public function testTerminalQualityGateRequiresApplicableEvidenceForEveryLane(): void {
		$workflow = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/quality.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		self::assertIsString( $workflow );

		$terminalStart = strpos( $workflow, "  quality:\n    name: Quality\n" );
		self::assertIsInt( $terminalStart );
		$terminal = substr( $workflow, $terminalStart );

		self::assertStringContainsString( 'if: ${{ always() }}', $terminal );
		foreach ( array( 'runtime-archive', 'baseline', 'repository-quality', 'release-candidate-install' ) as $need ) {
			self::assertStringContainsString( '- ' . $need, $terminal );
		}
		self::assertStringContainsString( 'test "$RUNTIME_ARCHIVE_RESULT" = success', $terminal );
		self::assertStringContainsString( 'test "$BASELINE_RESULT" = success', $terminal );
		self::assertStringNotContainsString( 'ADMITTED', $terminal );

		$fullStart             = strpos( $terminal, 'if [[ "$LANE" == full ]]' );
		$releaseCandidateStart = strpos( $terminal, 'elif [[ "$LANE" == release-candidate ]]' );
		$unsupportedStart      = strpos( $terminal, 'else', $releaseCandidateStart );
		self::assertIsInt( $fullStart );
		self::assertIsInt( $releaseCandidateStart );
		self::assertIsInt( $unsupportedStart );
		self::assertTrue( $fullStart < $releaseCandidateStart );
		self::assertTrue( $releaseCandidateStart < $unsupportedStart );

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

	public function testProfileBReleaseCallerAndPromotionManifestArePinned(): void {
		$root = dirname( __DIR__, 2 );
		$release = (string) file_get_contents( $root . '/.github/workflows/release-please.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		$quality = (string) file_get_contents( $root . '/.github/workflows/quality.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.
		$config = json_decode( (string) file_get_contents( $root . '/release-please-config.json' ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local workflow contract.

		self::assertStringContainsString( 'uses: RocketsAreNostalgic/.github/.github/workflows/release-profile-b.yml@cb42ecd841916ebe73f147e5b933cd7bcc392db8', $release );
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
