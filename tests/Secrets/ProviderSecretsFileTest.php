<?php

declare(strict_types=1);

namespace Tests\Secrets;

// Direct local filesystem operations are the behavior under test; WordPress filesystem abstractions cannot verify atomic sidecar semantics.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions.error_log_var_export
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\PackageBlueprint;
use RAN\Secrets\EncryptedSecretsEnvelopeCodec;
use RAN\Secrets\PrivateLocationCandidateResolver;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsRuntimeAvailability;
use RAN\Secrets\SiteKeyStore;
use RuntimeException;
use Tests\RepositoryProvider\Support\ShippedSecretPolicyCatalog;

#[CoversClass( SecretsFile::class )]
final class ProviderSecretsFileTest extends TestCase {

	private const GITHUB_TOKEN          = 'sentinel-github-token';
	private const BITBUCKET_TOKEN       = 'sentinel-bitbucket-token';
	private const GITHUB_WEBHOOK_SECRET = 'sentinel-github-webhook-secret-0001';
	private const BITBUCKET_WEBHOOK     = 'sentinel-bitbucket-webhook-secret-1';

	private string $directory;
	private string $path;
	private string $keyPath;

	protected function setUp(): void {
		$temporary = realpath( sys_get_temp_dir() );
		self::assertIsString( $temporary );
		$this->directory = $temporary . '/ran-booster-secrets-' . bin2hex( random_bytes( 8 ) );
		$this->path      = $this->directory . '/secrets.json';
		$this->keyPath   = $this->directory . '/test-key';

		self::assertTrue( mkdir( $this->directory, 0700 ) );
	}

	protected function tearDown(): void {
		$this->removeFixturePath( $this->directory );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConfiguredPathRejectsAnUnsafeWordPressRoot(): void {
		$wordpress = $this->directory . '/site/public';
		$content   = $wordpress . '/wp-content';
		$plugin    = $content . '/plugins/ran-booster';
		$private   = $wordpress . '/private';
		self::assertTrue( mkdir( $plugin, 0700, true ) );
		self::assertTrue( mkdir( $private, 0700 ) );
		define( 'ABSPATH', $wordpress );
		define( 'WP_CONTENT_DIR', $content );
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', $private . '/secrets.json' );
		$_SERVER['DOCUMENT_ROOT'] = $wordpress;

		$secrets = new SecretsFile(
			null,
			array(),
			ShippedSecretPolicyCatalog::create(),
			$this->keyStore(),
			new EncryptedSecretsEnvelopeCodec(),
			new PrivateLocationCandidateResolver( '/not-the-test-temporary-root' )
		);

		$this->assertConfiguredPathRejectedWithoutLeak( $secrets );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConfiguredPathRejectsASymlinkedAncestor(): void {
		$wordpress = $this->directory . '/site/public';
		$content   = $wordpress . '/wp-content';
		$plugin    = $content . '/plugins/ran-booster';
		$private   = $this->directory . '/private-real/secure';
		self::assertTrue( mkdir( $plugin, 0700, true ) );
		self::assertTrue( mkdir( $private, 0700, true ) );
		self::assertTrue( symlink( dirname( $private ), $this->directory . '/private-link' ) );
		define( 'ABSPATH', $wordpress );
		define( 'WP_CONTENT_DIR', $content );
		define(
			'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE',
			$this->directory . '/private-link/secure/secrets.json'
		);
		$_SERVER['DOCUMENT_ROOT'] = $wordpress;

		$secrets = new SecretsFile(
			null,
			array(),
			ShippedSecretPolicyCatalog::create(),
			$this->keyStore(),
			new EncryptedSecretsEnvelopeCodec(),
			new PrivateLocationCandidateResolver( '/not-the-test-temporary-root' )
		);

		$this->assertConfiguredPathRejectedWithoutLeak( $secrets );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConfiguredPrivatePathRemainsReadOnlyUntilFirstWrite(): void {
		$wordpress = $this->directory . '/site/public';
		$content   = $wordpress . '/wp-content';
		$plugin    = $content . '/plugins/ran-booster';
		$private   = $this->directory . '/private';
		self::assertTrue( mkdir( $plugin, 0700, true ) );
		self::assertTrue( mkdir( $private, 0700 ) );
		define( 'ABSPATH', $wordpress );
		define( 'WP_CONTENT_DIR', $content );
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', $private . '/secrets.json' );
		$_SERVER['DOCUMENT_ROOT'] = $wordpress;

		$secrets = new SecretsFile(
			null,
			array(),
			ShippedSecretPolicyCatalog::create(),
			$this->keyStore(),
			new EncryptedSecretsEnvelopeCodec(),
			new PrivateLocationCandidateResolver( '/not-the-test-temporary-root' )
		);
		self::assertFalse( $secrets->hasHealthyManagedStorage() );

		$secrets->saveCredential(
			'gh',
			'configured_first_write',
			array(
				'label'         => 'Configured first write',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			self::GITHUB_TOKEN
		);

		self::assertTrue( $secrets->hasHealthyManagedStorage() );
		self::assertFileExists( $private . '/secrets.json' );
		self::assertFileExists( $private . '/secrets.json.lock' );
		self::assertFileExists( $this->keyPath );
	}

	public function testProviderRecordsAreIsolatedAndDisplayProfilesAreFullyRedacted(): void {
		$secrets = $this->secretsFile();
		$secrets->saveCredential(
			'gh',
			'gh_primary',
			array(
				'label'         => 'GitHub primary',
				'kind'          => 'classic',
				'configuration' => array(
					'owner' => '',
				),
			),
			self::GITHUB_TOKEN
		);
		$secrets->saveCredential(
			'bb',
			'bb_primary',
			array(
				'label'         => 'Bitbucket primary',
				'kind'          => 'api-token',
				'configuration' => array(
					'workspace' => 'rockets-are-nostalgic',
					'email'     => 'deploy@example.test',
				),
			),
			self::BITBUCKET_TOKEN
		);
		$secrets->saveWebhook(
			'gh',
			'gh_webhook',
			array(
				'label'  => 'GitHub webhook',
				'scope'  => 'global',
				'target' => '',
			),
			self::GITHUB_WEBHOOK_SECRET
		);
		$secrets->saveWebhook(
			'bb',
			'bb_webhook',
			array(
				'label'  => 'Bitbucket webhook',
				'scope'  => 'workspace',
				'target' => 'rockets-are-nostalgic',
			),
			self::BITBUCKET_WEBHOOK
		);

		self::assertSame( array( 'gh_primary' ), array_keys( $secrets->credentialProfiles( 'gh' ) ) );
		self::assertSame( array( 'bb_primary' ), array_keys( $secrets->credentialProfiles( 'bb' ) ) );
		self::assertSame( array( 'gh_webhook' ), array_keys( $secrets->webhookProfiles( 'gh' ) ) );
		self::assertSame( array( 'bb_webhook' ), array_keys( $secrets->webhookProfiles( 'bb' ) ) );
		self::assertSame(
			self::GITHUB_TOKEN,
			$secrets->credentialMaterial( 'gh', 'gh_primary' )['secret']
		);
		self::assertSame(
			self::BITBUCKET_TOKEN,
			$secrets->credentialMaterial( 'bb', 'bb_primary' )['secret']
		);
		self::assertSame(
			self::GITHUB_WEBHOOK_SECRET,
			$secrets->webhookMaterials( 'gh' )['gh_webhook']['secret']
		);
		self::assertSame(
			self::BITBUCKET_WEBHOOK,
			$secrets->webhookMaterials( 'bb' )['bb_webhook']['secret']
		);

		foreach ( array_merge( $secrets->credentialProfiles( 'gh' ), $secrets->credentialProfiles( 'bb' ) ) as $profile ) {
			self::assertArrayNotHasKey( 'secret', $profile );
			self::assertTrue( $profile['configured'] );
		}
		foreach ( array_merge( $secrets->webhookProfiles( 'gh' ), $secrets->webhookProfiles( 'bb' ) ) as $profile ) {
			self::assertArrayNotHasKey( 'secret', $profile );
			self::assertTrue( $profile['configured'] );
		}

		$secrets->deleteCredential( 'gh', 'gh_primary' );
		$secrets->deleteWebhook( 'gh', 'gh_webhook' );

		self::assertSame( array(), $secrets->credentialProfiles( 'gh' ) );
		self::assertSame( array( 'bb_primary' ), array_keys( $secrets->credentialProfiles( 'bb' ) ) );
		self::assertSame( array(), $secrets->webhookProfiles( 'gh' ) );
		self::assertSame( array( 'bb_webhook' ), array_keys( $secrets->webhookProfiles( 'bb' ) ) );
	}

	public function testManagedStorageDeletionAuthenticatesAndRemovesExactMaterialIdempotently(): void {
		$secrets = $this->secretsFile();
		$secrets->saveCredential(
			'gh',
			'delete_managed_storage',
			array(
				'label'         => 'Delete managed storage',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			self::GITHUB_TOKEN
		);

		$secrets->deleteManagedStorage();

		self::assertFileDoesNotExist( $this->path );
		self::assertFileDoesNotExist( $this->path . '.lock' );
		self::assertFileDoesNotExist( $this->keyPath );

		$secrets->deleteManagedStorage();
		self::assertFileDoesNotExist( $this->path );
		self::assertFileDoesNotExist( $this->path . '.lock' );
		self::assertFileDoesNotExist( $this->keyPath );
	}

	public function testPristineManagedStorageDeletionDoesNotRequireSodium(): void {
		$secrets = new SecretsFile(
			$this->path,
			array(),
			ShippedSecretPolicyCatalog::create(),
			$this->keyStore(),
			new EncryptedSecretsEnvelopeCodec(),
			null,
			new SecretsRuntimeAvailability( false, false )
		);

		$secrets->deleteManagedStorage();

		self::assertFileDoesNotExist( $this->path );
		self::assertFileDoesNotExist( $this->path . '.lock' );
		self::assertFileDoesNotExist( $this->keyPath );
	}

	public function testManagedStorageDeletionRequiresSodiumForExistingCiphertext(): void {
		$this->writeSidecar( $this->validDocument() );
		$secrets = new SecretsFile(
			$this->path,
			array(),
			ShippedSecretPolicyCatalog::create(),
			$this->keyStore(),
			new EncryptedSecretsEnvelopeCodec(),
			null,
			new SecretsRuntimeAvailability( false, false )
		);

		try {
			$secrets->deleteManagedStorage();
			self::fail( 'Existing ciphertext must not be deleted without authenticated-encryption support.' );
		} catch ( RuntimeException ) {
			self::assertFileExists( $this->path );
			self::assertFileExists( $this->path . '.lock' );
			self::assertFileExists( $this->keyPath );
		}
	}

	public function testManagedStorageDeletionAcceptsMissingLockAndKeyOnlyStates(): void {
		$this->writeSidecar( $this->validDocument() );
		self::assertTrue( unlink( $this->path . '.lock' ) );

		$this->secretsFile()->deleteManagedStorage();
		self::assertFileDoesNotExist( $this->path );
		self::assertFileDoesNotExist( $this->path . '.lock' );
		self::assertFileDoesNotExist( $this->keyPath );

		$this->keyStore()->loadOrCreate();
		$this->secretsFile()->deleteManagedStorage();
		self::assertFileDoesNotExist( $this->path );
		self::assertFileDoesNotExist( $this->path . '.lock' );
		self::assertFileDoesNotExist( $this->keyPath );
	}

	public function testManagedStorageDeletionRejectsTamperedCiphertextAndRetainsTheKey(): void {
		$this->writeSidecar( $this->validDocument() );
		$before = (string) file_get_contents( $this->path );
		self::assertNotFalse( file_put_contents( $this->path, $before . 'tampered' ) );

		try {
			$this->secretsFile()->deleteManagedStorage();
			self::fail( 'Tampered ciphertext must not be deleted.' );
		} catch ( RuntimeException ) {
			self::assertSame( $before . 'tampered', file_get_contents( $this->path ) );
			self::assertFileExists( $this->path . '.lock' );
			self::assertFileExists( $this->keyPath );
		}
	}

	public function testManagedStorageDeletionRejectsUnsafeSidecarIdentityAndPermissions(): void {
		$this->writeSidecar( $this->validDocument() );
		self::assertTrue( link( $this->path, $this->directory . '/hard-link' ) );

		try {
			$this->secretsFile()->deleteManagedStorage();
			self::fail( 'A hard-linked sidecar must not be deleted.' );
		} catch ( RuntimeException ) {
			self::assertFileExists( $this->path );
			self::assertFileExists( $this->keyPath );
		}

		self::assertTrue( unlink( $this->directory . '/hard-link' ) );
		self::assertTrue( chmod( $this->path, 0644 ) );
		try {
			$this->secretsFile()->deleteManagedStorage();
			self::fail( 'An insecure sidecar must not be deleted.' );
		} catch ( RuntimeException ) {
			self::assertFileExists( $this->path );
			self::assertFileExists( $this->keyPath );
		}
	}

	public function testManagedStorageDeletionRejectsASymlinkWithoutTouchingItsTarget(): void {
		$this->keyStore()->loadOrCreate();
		$this->writeLock();
		$target = $this->directory . '/foreign-target';
		self::assertNotFalse( file_put_contents( $target, 'foreign' ) );
		self::assertTrue( symlink( $target, $this->path ) );

		try {
			$this->secretsFile()->deleteManagedStorage();
			self::fail( 'A symlinked sidecar must not be deleted.' );
		} catch ( RuntimeException ) {
			self::assertTrue( is_link( $this->path ) );
			self::assertSame( 'foreign', file_get_contents( $target ) );
			self::assertFileExists( $this->keyPath );
		}
	}

	public function testManagedStorageDeletionLeavesTheKeyUntilCiphertextRemovalSucceeds(): void {
		$this->writeSidecar( $this->validDocument() );
		$secrets = new class(
			$this->path,
			array(),
			ShippedSecretPolicyCatalog::create(),
			$this->keyStore(),
			new EncryptedSecretsEnvelopeCodec()
		) extends SecretsFile {
			protected function removeFile( string $path ): bool {
				if ( $path === $this->path() ) {
					return false;
				}

				return parent::removeFile( $path );
			}
		};

		try {
			$secrets->deleteManagedStorage();
			self::fail( 'A ciphertext deletion failure must be reported.' );
		} catch ( RuntimeException ) {
			self::assertFileExists( $this->path );
			self::assertFileExists( $this->path . '.lock' );
			self::assertFileExists( $this->keyPath );
		}

		$this->secretsFile()->deleteManagedStorage();
		self::assertFileDoesNotExist( $this->path );
		self::assertFileDoesNotExist( $this->path . '.lock' );
		self::assertFileDoesNotExist( $this->keyPath );
	}

	public function testTemporaryCredentialIsProviderScopedRedactedAndNeverPersisted(): void {
		$secrets = $this->secretsFile();

		$temporaryId = $secrets->withTemporaryCredential(
			'gh',
			array(
				'label'         => 'Transferred credential',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			self::GITHUB_TOKEN,
			function ( string $credentialId ) use ( $secrets ): string {
				self::assertArrayNotHasKey( $credentialId, $secrets->credentialProfiles( 'gh' ) );
				self::assertSame( self::GITHUB_TOKEN, $secrets->credentialMaterial( 'gh', $credentialId )['secret'] );
				self::assertSame( array(), $secrets->credentialMaterials( 'bb' ) );
				self::assertFileDoesNotExist( $this->path );

				return $credentialId;
			}
		);

		self::assertDoesNotMatchRegularExpression( '/[^A-Za-z0-9_-]/', $temporaryId );
		self::assertNull( $secrets->credentialMaterial( 'gh', $temporaryId ) );
		self::assertSame( array(), $secrets->credentialProfiles( 'gh' ) );
		self::assertFileDoesNotExist( $this->path );
	}

	public function testTemporaryCredentialIsRemovedWhenTheCallbackFails(): void {
		$secrets     = $this->secretsFile();
		$temporaryId = '';

		try {
			$secrets->withTemporaryCredential(
				'gh',
				array(
					'label'         => 'Transferred credential',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				self::GITHUB_TOKEN,
				static function ( string $credentialId ) use ( &$temporaryId ): void {
					$temporaryId = $credentialId;
					throw new RuntimeException( 'The test callback failed.' );
				}
			);
			self::fail( 'Expected the temporary credential callback failure.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'The test callback failed.', $exception->getMessage() );
		}

		self::assertNotSame( '', $temporaryId );
		self::assertNull( $secrets->credentialMaterial( 'gh', $temporaryId ) );
		self::assertSame( array(), $secrets->credentialProfiles( 'gh' ) );
		self::assertFileDoesNotExist( $this->path );
	}

	public function testImportedBlueprintCredentialIsDeterministicAndReusedWithoutASecondWrite(): void {
		$credential = new BlueprintCredential(
			'gh',
			'Imported deployment token',
			'classic',
			array( 'owner' => '' ),
			self::GITHUB_TOKEN,
			array(
				$this->identity( 'plugin', 'example/example.php' ),
				$this->identity( 'theme', 'example-theme' ),
			)
		);
		$blueprint  = new PackageBlueprint(
			array(
				$this->package( 'plugin', 'example/example.php', 'Example Plugin', 'gh' ),
				$this->package( 'theme', 'example-theme', 'Example Theme', 'gh' ),
			),
			array( $credential )
		);
		$secrets    = $this->secretsFile();

		$first = $secrets->importCredentialsIfAbsent( $blueprint, $credential );

		self::assertCount( 1, $first );
		self::assertMatchesRegularExpression( '/^portable_[A-Za-z0-9_-]{55}$/', $first[0] );
		self::assertSame( self::GITHUB_TOKEN, $secrets->credentialMaterial( 'gh', $first[0] )['secret'] );
		self::assertArrayNotHasKey( 'secret', $secrets->credentialProfiles( 'gh' )[ $first[0] ] );
		$before = file_get_contents( $this->path );

		self::assertSame( $first, $secrets->importCredentialsIfAbsent( $blueprint, $credential ) );
		self::assertSame( $before, file_get_contents( $this->path ) );
	}

	public function testImportedCredentialConflictNeverOverwritesTheTargetRecord(): void {
		$credential = $this->githubBlueprintCredential();
		$blueprint  = $this->blueprint( $credential );
		$secrets    = $this->secretsFile();
		$id         = $secrets->importCredentialsIfAbsent( $blueprint, $credential )[0];
		$secrets->saveCredential(
			'gh',
			$id,
			array(
				'label'         => 'Conflicting local token',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			'sentinel-conflicting-local-token'
		);
		$before = file_get_contents( $this->path );

		try {
			$secrets->importCredentialsIfAbsent( $blueprint, $credential );
			self::fail( 'An existing different target credential must block import.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( self::GITHUB_TOKEN, $exception->getMessage() );
			self::assertStringNotContainsString( 'sentinel-conflicting-local-token', $exception->getMessage() );
		}

		self::assertSame( $before, file_get_contents( $this->path ) );
		self::assertSame( 'sentinel-conflicting-local-token', $secrets->credentialMaterial( 'gh', $id )['secret'] );
	}

	public function testInvalidSelectedCredentialLeavesEveryTargetRecordUntouched(): void {
		$github    = $this->githubBlueprintCredential();
		$bitbucket = new BlueprintCredential(
			'bb',
			'Invalid imported Bitbucket token',
			'api-token',
			array(
				'workspace' => 'rockets-are-nostalgic',
				'email'     => 'not-an-email',
			),
			self::BITBUCKET_TOKEN,
			array( $this->identity( 'theme', 'example-theme' ) )
		);
		$blueprint = new PackageBlueprint(
			array(
				$this->package( 'plugin', 'example/example.php', 'Example Plugin', 'gh' ),
				$this->package( 'theme', 'example-theme', 'Example Theme', 'bb' ),
			),
			array( $github, $bitbucket )
		);
		$secrets   = $this->secretsFile();
		$secrets->saveCredential(
			'gh',
			'existing_target',
			array(
				'label'         => 'Existing target token',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			'sentinel-existing-target-token'
		);
		$before = file_get_contents( $this->path );

		try {
			$secrets->importCredentialsIfAbsent( $blueprint, $github, $bitbucket );
			self::fail( 'Every selected credential must validate before the sidecar is changed.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( self::GITHUB_TOKEN, $exception->getMessage() );
			self::assertStringNotContainsString( self::BITBUCKET_TOKEN, $exception->getMessage() );
		}

		self::assertSame( $before, file_get_contents( $this->path ) );
		self::assertNull( $secrets->credentialMaterial( 'gh', 'portable_invalid' ) );
		self::assertSame( 'sentinel-existing-target-token', $secrets->credentialMaterial( 'gh', 'existing_target' )['secret'] );
	}

	public function testConstantsWinWithoutBeingPersisted(): void {
		$this->writeSidecar(
			array(
				'schema_version' => 2,
				'credentials'    => array(
					'gh' => array(
						'file' => array(
							'label'         => 'Stored GitHub credential',
							'kind'          => 'classic',
							'configuration' => array( 'owner' => '' ),
							'secret'        => 'sentinel-stored-github-token',
						),
					),
				),
				'webhooks'       => array(
					'gh' => array(
						'file' => array(
							'label'        => 'Stored GitHub webhook',
							'scope'        => 'global',
							'target'       => '',
							'authority_id' => '',
							'secret'       => 'sentinel-stored-webhook-secret-0001',
						),
					),
				),
			)
		);
		$secrets = $this->secretsFile(
			array(
				'RAN_BOOSTER_GITHUB_TOKEN'             => self::GITHUB_TOKEN,
				'RAN_BOOSTER_GITHUB_WEBHOOK_SECRET'    => self::GITHUB_WEBHOOK_SECRET,
				'RAN_BOOSTER_BITBUCKET_WEBHOOK_SECRET' => self::GITHUB_WEBHOOK_SECRET,
				'RAN_BOOSTER_BITBUCKET_WORKSPACE'      => 'rockets-are-nostalgic',
				'RAN_BOOSTER_BITBUCKET_EMAIL'          => 'deploy@example.test',
				'RAN_BOOSTER_BITBUCKET_TOKEN'          => self::BITBUCKET_TOKEN,
			)
		);

		self::assertSame(
			self::GITHUB_TOKEN,
			$secrets->credentialMaterial( 'gh', 'constant' )['secret']
		);
		self::assertTrue( $secrets->credentialProfiles( 'gh' )['constant']['immutable'] );
		self::assertSame(
			array(
				'workspace' => 'rockets-are-nostalgic',
				'email'     => 'deploy@example.test',
			),
			$secrets->credentialMaterial( 'bb', 'constant' )['configuration']
		);
		self::assertSame(
			self::BITBUCKET_TOKEN,
			$secrets->credentialMaterial( 'bb', 'constant' )['secret']
		);
		self::assertSame( 'api-token', $secrets->credentialMaterial( 'bb', 'constant' )['kind'] );
		self::assertTrue( $secrets->credentialProfiles( 'bb' )['constant']['immutable'] );
		self::assertSame(
			self::GITHUB_WEBHOOK_SECRET,
			$secrets->webhookMaterials( 'gh' )['constant']['secret']
		);
		self::assertTrue( $secrets->webhookProfiles( 'gh' )['constant']['immutable'] );
		self::assertSame(
			self::GITHUB_WEBHOOK_SECRET,
			$secrets->webhookMaterials( 'bb' )['constant']['secret']
		);
		self::assertTrue( $secrets->webhookProfiles( 'bb' )['constant']['immutable'] );

		self::assertFalse( $secrets->verifyAndSecure() );
		$contents = (string) file_get_contents( $this->path );
		self::assertStringNotContainsString( self::GITHUB_TOKEN, $contents );
		self::assertStringNotContainsString( self::GITHUB_WEBHOOK_SECRET, $contents );
		self::assertStringNotContainsString( self::BITBUCKET_TOKEN, $contents );
		self::assertStringNotContainsString( 'rockets-are-nostalgic', $contents );
		self::assertStringNotContainsString( 'deploy@example.test', $contents );
		self::assertStringNotContainsString( 'sentinel-stored-github-token', $contents );
		self::assertStringNotContainsString( 'sentinel-stored-webhook-secret-0001', $contents );
	}

	public function testPartialBitbucketConstantsFailWithoutDisclosingTheirValues(): void {
		$fixtures = array(
			'workspace only'       => array(
				'RAN_BOOSTER_BITBUCKET_WORKSPACE' => 'sentinel-partial-workspace',
			),
			'email and token only' => array(
				'RAN_BOOSTER_BITBUCKET_EMAIL' => 'sentinel-partial@example.test',
				'RAN_BOOSTER_BITBUCKET_TOKEN' => 'sentinel-partial-token',
			),
		);

		foreach ( $fixtures as $name => $constants ) {
			try {
				$this->secretsFile( $constants )->credentialProfiles( 'bb' );
				self::fail( $name . ' must be rejected.' );
			} catch ( RuntimeException $exception ) {
				foreach ( $constants as $value ) {
					self::assertStringNotContainsString( $value, $exception->getMessage() );
				}
			}
		}
	}

	public function testTheSameCredentialIdIsIndependentAcrossProviders(): void {
		$secrets = $this->secretsFile();
		$secrets->saveCredential(
			'gh',
			'shared_profile',
			array(
				'label'         => 'GitHub shared ID',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			self::GITHUB_TOKEN
		);
		$secrets->saveCredential(
			'bb',
			'shared_profile',
			array(
				'label'         => 'Bitbucket shared ID',
				'kind'          => 'api-token',
				'configuration' => array(
					'workspace' => 'rockets-are-nostalgic',
					'email'     => 'deploy@example.test',
				),
			),
			self::BITBUCKET_TOKEN
		);

		self::assertSame( self::GITHUB_TOKEN, $secrets->credentialMaterial( 'gh', 'shared_profile' )['secret'] );
		self::assertSame( self::BITBUCKET_TOKEN, $secrets->credentialMaterial( 'bb', 'shared_profile' )['secret'] );

		$secrets->deleteCredential( 'gh', 'shared_profile' );

		self::assertNull( $secrets->credentialMaterial( 'gh', 'shared_profile' ) );
		self::assertSame( self::BITBUCKET_TOKEN, $secrets->credentialMaterial( 'bb', 'shared_profile' )['secret'] );
	}

	public function testBlankSecretUpdatePreservesStoredMaterial(): void {
		$secrets = $this->secretsFile();
		$secrets->saveCredential(
			'gh',
			'gh_update',
			array(
				'label'         => 'Before update',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			self::GITHUB_TOKEN
		);
		$secrets->saveCredential(
			'gh',
			'gh_update',
			array(
				'label'         => 'After update',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			''
		);

		$material = $secrets->credentialMaterial( 'gh', 'gh_update' );
		self::assertSame( 'After update', $material['label'] );
		self::assertSame( self::GITHUB_TOKEN, $material['secret'] );
	}

	public function testUnknownCanonicalRecordFieldsFailClosedWithoutChangingTheOriginal(): void {
		$baseRecord = array(
			'label'         => 'Canonical credential',
			'kind'          => 'classic',
			'configuration' => array( 'owner' => '' ),
			'secret'        => self::GITHUB_TOKEN,
		);
		$fixtures   = array(
			'unknown record metadata'     => $baseRecord + array( 'sentinel_metadata' => 'sentinel-metadata-value' ),
			'unknown configuration field' => array_replace(
				$baseRecord,
				array(
					'configuration' => array(
						'owner'                  => '',
						'sentinel_configuration' => 'sentinel-configuration-value',
					),
				)
			),
		);

		foreach ( $fixtures as $name => $record ) {
			$this->writeSidecar(
				array(
					'schema_version' => 2,
					'credentials'    => array(
						'gh' => array( 'gh_strict' => $record ),
					),
					'webhooks'       => array(),
				)
			);
			$before = file_get_contents( $this->path );

			try {
				$this->secretsFile()->verifyAndSecure();
				self::fail( $name . ' must be rejected.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringNotContainsString( 'sentinel-', $exception->getMessage() );
			}

			self::assertSame( $before, file_get_contents( $this->path ), $name );
		}
	}

	public function testObsoleteSchemaFieldsAreRejectedWithoutChangingTheOriginal(): void {
		$this->writeSidecar(
			array(
				'schema_version' => 2,
				'credentials'    => array(),
				'webhooks'       => array(),
				'obsolete_data'  => array( 'sentinel-obsolete-value' ),
			)
		);
		$before = file_get_contents( $this->path );

		try {
			$this->secretsFile()->verifyAndSecure();
			self::fail( 'Obsolete schema fields must be rejected.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( 'sentinel-', $exception->getMessage() );
		}

		self::assertSame( $before, file_get_contents( $this->path ) );
	}

	public function testReservedConstantIdCannotBeWrittenOrDeleted(): void {
		$secrets    = $this->secretsFile();
		$operations = array(
			'save credential'   => static fn() => $secrets->saveCredential(
				'gh',
				'constant',
				array(
					'label'         => 'Reserved credential',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				self::GITHUB_TOKEN
			),
			'delete credential' => static fn() => $secrets->deleteCredential( 'gh', 'constant' ),
			'save webhook'      => static fn() => $secrets->saveWebhook(
				'gh',
				'constant',
				array(
					'label'  => 'Reserved webhook',
					'scope'  => 'global',
					'target' => '',
				),
				self::GITHUB_WEBHOOK_SECRET
			),
			'delete webhook'    => static fn() => $secrets->deleteWebhook( 'gh', 'constant' ),
		);

		foreach ( $operations as $name => $operation ) {
			try {
				$operation();
				self::fail( $name . ' must be rejected.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringNotContainsString( self::GITHUB_TOKEN, $exception->getMessage() );
				self::assertStringNotContainsString( self::GITHUB_WEBHOOK_SECRET, $exception->getMessage() );
			}
		}
	}

	public function testMalformedAndFutureSchemasFailWithoutChangingTheOriginal(): void {
		$fixtures = array(
			'malformed current schema' => array(
				'schema_version' => 2,
				'credentials'    => array(
					'gh' => array(
						'broken' => array(
							'label'         => 'Broken record',
							'kind'          => 'classic',
							'configuration' => array(),
							'secret'        => array( 'sentinel-malformed-secret' ),
						),
					),
				),
			),
			'future schema'            => array(
				'schema_version' => 999,
				'credentials'    => array(),
				'future_data'    => 'sentinel-future-value',
			),
		);

		foreach ( $fixtures as $name => $fixture ) {
			$this->writeSidecar( $fixture );
			$before = file_get_contents( $this->path );

			try {
				$this->secretsFile()->verifyAndSecure();
				self::fail( $name . ' must be rejected.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringNotContainsString( 'sentinel-', $exception->getMessage() );
			}

			self::assertSame( $before, file_get_contents( $this->path ), $name );
		}
	}

	public function testAuthenticatedButNonCanonicalPayloadFailsWithoutRewritingCiphertext(): void {
		$key       = $this->keyStore()->loadOrCreate()['key'];
		$plaintext = "{\n"
			. "  \"webhooks\": {},\n"
			. "  \"credentials\": {},\n"
			. '  "schema_version": 2'
			. "\n}\n";
		$envelope  = ( new EncryptedSecretsEnvelopeCodec() )->encrypt( $plaintext, $key );
		self::assertNotFalse( file_put_contents( $this->path, $envelope ) );
		self::assertTrue( chmod( $this->path, 0600 ) );
		$this->writeLock();

		try {
			$this->secretsFile()->verifyAndSecure();
			self::fail( 'An authenticated non-canonical payload must fail closed.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( $plaintext, $exception->getMessage() );
		}

		self::assertSame( $envelope, file_get_contents( $this->path ) );
	}

	public function testReadingThroughASymbolicLinkIsRejected(): void {
		$target = $this->directory . '/sidecar-target.json';
		$this->writeSidecar(
			array(
				'schema_version' => 2,
				'credentials'    => array(),
				'webhooks'       => array(),
			),
			$target
		);
		self::assertTrue( symlink( $target, $this->path ) );

		$this->expectException( RuntimeException::class );
		$this->secretsFile()->credentialProfiles( 'gh' );
	}

	public function testWritingCreatesAFileReadableOnlyByItsOwner(): void {
		$this->secretsFile()->saveCredential(
			'gh',
			'gh_private',
			array(
				'label'         => 'Private file test',
				'kind'          => 'classic',
				'configuration' => array(
					'owner' => '',
				),
			),
			self::GITHUB_TOKEN
		);

		clearstatcache( true, $this->path );
		self::assertSame( 0600, fileperms( $this->path ) & 0777 );
	}

	public function testVerifyAndSecureRepairsInsecureCanonicalFilePermissions(): void {
		$this->writeSidecar(
			array(
				'schema_version' => 2,
				'credentials'    => array(),
				'webhooks'       => array(),
			)
		);
		self::assertTrue( chmod( $this->path, 0644 ) );
		clearstatcache( true, $this->path );
		self::assertSame( 0644, fileperms( $this->path ) & 0777 );
		$before = file_get_contents( $this->path );

		self::assertTrue( $this->secretsFile()->verifyAndSecure() );

		clearstatcache( true, $this->path );
		self::assertSame( 0600, fileperms( $this->path ) & 0777 );
		self::assertSame( $before, file_get_contents( $this->path ) );
	}

	public function testInvalidCompleteBitbucketConstantsFailWithoutDisclosingTheirValues(): void {
		$fixtures = array(
			'invalid workspace' => array(
				'RAN_BOOSTER_BITBUCKET_WORKSPACE' => 'sentinel invalid workspace!',
				'RAN_BOOSTER_BITBUCKET_EMAIL'     => 'deploy@example.test',
				'RAN_BOOSTER_BITBUCKET_TOKEN'     => 'sentinel-invalid-workspace-token',
			),
			'invalid email'     => array(
				'RAN_BOOSTER_BITBUCKET_WORKSPACE' => 'rockets-are-nostalgic',
				'RAN_BOOSTER_BITBUCKET_EMAIL'     => 'sentinel-invalid-email',
				'RAN_BOOSTER_BITBUCKET_TOKEN'     => 'sentinel-invalid-email-token',
			),
			'colon in email'    => array(
				'RAN_BOOSTER_BITBUCKET_WORKSPACE' => 'rockets-are-nostalgic',
				'RAN_BOOSTER_BITBUCKET_EMAIL'     => 'sentinel@example.test:smuggled',
				'RAN_BOOSTER_BITBUCKET_TOKEN'     => 'sentinel-colon-email-token',
			),
			'control in email'  => array(
				'RAN_BOOSTER_BITBUCKET_WORKSPACE' => 'rockets-are-nostalgic',
				'RAN_BOOSTER_BITBUCKET_EMAIL'     => "sentinel@example.test\r",
				'RAN_BOOSTER_BITBUCKET_TOKEN'     => 'sentinel-control-email-token',
			),
			'control in token'  => array(
				'RAN_BOOSTER_BITBUCKET_WORKSPACE' => 'rockets-are-nostalgic',
				'RAN_BOOSTER_BITBUCKET_EMAIL'     => 'deploy@example.test',
				'RAN_BOOSTER_BITBUCKET_TOKEN'     => "sentinel-control-token\n",
			),
		);

		foreach ( $fixtures as $name => $constants ) {
			try {
				$this->secretsFile( $constants )->credentialProfiles( 'bb' );
				self::fail( $name . ' must be rejected.' );
			} catch ( RuntimeException $exception ) {
				foreach ( $constants as $value ) {
					self::assertStringNotContainsString( $value, $exception->getMessage() );
				}
			}
		}
	}

	public function testBitbucketCredentialWritesRejectBasicAuthInjectionCharactersWithoutPersistence(): void {
		$secrets = $this->secretsFile();
		$secrets->saveCredential(
			'bb',
			'bb_injection_test',
			array(
				'label'         => 'Bitbucket injection test',
				'kind'          => 'api-token',
				'configuration' => array(
					'workspace' => 'rockets-are-nostalgic',
					'email'     => 'deploy@example.test',
				),
			),
			self::BITBUCKET_TOKEN
		);
		$before   = file_get_contents( $this->path );
		$fixtures = array(
			'colon in email' => array( 'sentinel@example.test:smuggled', 'sentinel-safe-token' ),
			'NUL in email'   => array( "sentinel\0@example.test", 'sentinel-safe-token' ),
			'CR in email'    => array( "sentinel@example.test\r", 'sentinel-safe-token' ),
			'LF in email'    => array( "sentinel@example.test\n", 'sentinel-safe-token' ),
			'DEL in email'   => array( "sentinel@example.test\x7F", 'sentinel-safe-token' ),
			'NUL in token'   => array( 'deploy@example.test', "sentinel\0token" ),
			'CR in token'    => array( 'deploy@example.test', "sentinel\rtoken" ),
			'LF in token'    => array( 'deploy@example.test', "sentinel\ntoken" ),
			'DEL in token'   => array( 'deploy@example.test', "sentinel\x7Ftoken" ),
		);

		foreach ( $fixtures as $name => list($email, $token) ) {
			try {
				$secrets->saveCredential(
					'bb',
					'bb_injection_test',
					array(
						'label'         => 'Bitbucket injection attempt',
						'kind'          => 'api-token',
						'configuration' => array(
							'workspace' => 'rockets-are-nostalgic',
							'email'     => $email,
						),
					),
					$token
				);
				self::fail( $name . ' must be rejected.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringNotContainsString( 'sentinel', $exception->getMessage(), $name );
			}

			self::assertSame( $before, file_get_contents( $this->path ), $name );
		}
	}

	public function testMalformedBitbucketFileCredentialsFailWithoutDisclosingOrRewritingSecrets(): void {
		$baseRecord = array(
			'label'         => 'Bitbucket deployment',
			'kind'          => 'api-token',
			'configuration' => array(
				'workspace' => 'rockets-are-nostalgic',
				'email'     => 'deploy@example.test',
			),
			'secret'        => self::BITBUCKET_TOKEN,
		);
		$fixtures   = array(
			'unsupported kind'             => array_replace( $baseRecord, array( 'kind' => 'app-password' ) ),
			'missing workspace'            => array_replace(
				$baseRecord,
				array( 'configuration' => array( 'email' => 'deploy@example.test' ) )
			),
			'invalid workspace'            => array_replace(
				$baseRecord,
				array(
					'configuration' => array(
						'workspace' => 'invalid workspace!',
						'email'     => 'deploy@example.test',
					),
				)
			),
			'invalid email'                => array_replace(
				$baseRecord,
				array(
					'configuration' => array(
						'workspace' => 'rockets-are-nostalgic',
						'email'     => 'sentinel-invalid-email',
					),
				)
			),
			'colon in email'               => array_replace(
				$baseRecord,
				array(
					'configuration' => array(
						'workspace' => 'rockets-are-nostalgic',
						'email'     => 'deploy@example.test:sentinel-smuggled',
					),
				)
			),
			'control in email'             => array_replace(
				$baseRecord,
				array(
					'configuration' => array(
						'workspace' => 'rockets-are-nostalgic',
						'email'     => "deploy@example.test\n",
					),
				)
			),
			'control in credential secret' => array_replace( $baseRecord, array( 'secret' => "sentinel-token\x7F" ) ),
			'unsupported configuration'    => array_replace(
				$baseRecord,
				array(
					'configuration' => array(
						'workspace' => 'rockets-are-nostalgic',
						'email'     => 'deploy@example.test',
						'password'  => 'sentinel-unsupported-password',
					),
				)
			),
			'non-string credential secret' => array_replace( $baseRecord, array( 'secret' => array( self::BITBUCKET_TOKEN ) ) ),
		);

		foreach ( $fixtures as $name => $record ) {
			$this->writeSidecar(
				array(
					'schema_version' => 2,
					'credentials'    => array(
						'bb' => array( 'bb_invalid' => $record ),
					),
					'webhooks'       => array(),
				)
			);
			$before = file_get_contents( $this->path );

			try {
				$this->secretsFile()->credentialProfiles( 'bb' );
				self::fail( $name . ' must be rejected.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringNotContainsString( 'sentinel-', $exception->getMessage(), $name );
				self::assertStringNotContainsString( self::BITBUCKET_TOKEN, $exception->getMessage(), $name );
			}

			self::assertSame( $before, file_get_contents( $this->path ), $name );
		}
	}

	public function testSymbolicLinkLockPathRejectsMutationWithoutChangingItsTarget(): void {
		$target   = $this->directory . '/lock-target';
		$lock     = $this->path . '.lock';
		$sentinel = 'sentinel-lock-target-content';
		self::assertNotFalse( file_put_contents( $target, $sentinel ) );
		self::assertTrue( symlink( $target, $lock ) );

		try {
			$this->secretsFile()->saveCredential(
				'gh',
				'gh_lock_test',
				array(
					'label'         => 'Lock link test',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				self::GITHUB_TOKEN
			);
			self::fail( 'A symbolic-link lock path must be rejected.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( self::GITHUB_TOKEN, $exception->getMessage() );
		}

		self::assertSame( $sentinel, file_get_contents( $target ) );
		self::assertFileDoesNotExist( $this->path );
	}

	public function testConcurrentDistinctCredentialSavesRetainEveryRecord(): void {
		if ( ! function_exists( 'pcntl_fork' )
			|| ! function_exists( 'pcntl_waitpid' )
			|| ! function_exists( 'pcntl_wifexited' )
			|| ! function_exists( 'pcntl_wexitstatus' ) ) {
			self::markTestSkipped( 'The PCNTL extension is required for the concurrency test.' );
		}

		$barrier  = $this->directory . '/concurrent-start';
		$children = array();
		$count    = 6;

		for ( $index = 0; $index < $count; ++$index ) {
			$pid = pcntl_fork();
			if ( -1 === $pid ) {
				self::fail( 'Could not fork a sidecar writer.' );
			}

			if ( 0 === $pid ) {
				while ( ! is_file( $barrier ) ) {
					usleep( 1000 );
				}

				try {
					$this->secretsFile()->saveCredential(
						'gh',
						'concurrent_' . $index,
						array(
							'label'         => 'Concurrent ' . $index,
							'kind'          => 'classic',
							'configuration' => array( 'owner' => '' ),
						),
						'sentinel-concurrent-token-' . $index
					);
					exit( 0 );
				} catch ( \Throwable ) {
					exit( 1 );
				}
			}

			$children[] = $pid;
		}

		self::assertNotFalse( file_put_contents( $barrier, 'start' ) );
		foreach ( $children as $pid ) {
			self::assertSame( $pid, pcntl_waitpid( $pid, $status ) );
			self::assertTrue( pcntl_wifexited( $status ) );
			self::assertSame( 0, pcntl_wexitstatus( $status ) );
		}

		$materials = $this->secretsFile()->credentialMaterials( 'gh' );
		self::assertCount( $count, $materials );
		for ( $index = 0; $index < $count; ++$index ) {
			self::assertSame(
				'sentinel-concurrent-token-' . $index,
				$materials[ 'concurrent_' . $index ]['secret']
			);
		}
	}

	public function testManagedReadsWaitForTheSharedStoreLock(): void {
		if ( ! function_exists( 'pcntl_fork' )
			|| ! function_exists( 'pcntl_waitpid' )
			|| ! function_exists( 'pcntl_wifexited' )
			|| ! function_exists( 'pcntl_wexitstatus' ) ) {
			self::markTestSkipped( 'The PCNTL extension is required for the shared-lock test.' );
		}

		$this->writeSidecar( $this->validDocument() );
		$lock = fopen( $this->path . '.lock', 'r+b' );
		self::assertIsResource( $lock );
		self::assertTrue( flock( $lock, LOCK_EX ) );
		$finished = $this->directory . '/reader-finished';
		$pid      = pcntl_fork();
		self::assertNotSame( -1, $pid );

		if ( 0 === $pid ) {
			fclose( $lock );
			try {
				$this->secretsFile()->credentialProfiles( 'gh' );
				file_put_contents( $finished, 'finished' );
				exit( 0 );
			} catch ( \Throwable ) {
				exit( 1 );
			}
		}

		usleep( 100000 );
		self::assertFileDoesNotExist( $finished );
		self::assertTrue( flock( $lock, LOCK_UN ) );
		fclose( $lock );
		self::assertSame( $pid, pcntl_waitpid( $pid, $status ) );
		self::assertTrue( pcntl_wifexited( $status ) );
		self::assertSame( 0, pcntl_wexitstatus( $status ) );
		self::assertFileExists( $finished );
	}

	public function testManagedWritesWaitForExistingSharedReaders(): void {
		if ( ! function_exists( 'pcntl_fork' )
			|| ! function_exists( 'pcntl_waitpid' )
			|| ! function_exists( 'pcntl_wifexited' )
			|| ! function_exists( 'pcntl_wexitstatus' ) ) {
			self::markTestSkipped( 'The PCNTL extension is required for the exclusive-writer test.' );
		}

		$this->writeSidecar( $this->validDocument() );
		$before = file_get_contents( $this->path );
		$lock   = fopen( $this->path . '.lock', 'r+b' );
		self::assertIsResource( $lock );
		self::assertTrue( flock( $lock, LOCK_SH ) );
		$ready    = $this->directory . '/writer-ready';
		$finished = $this->directory . '/writer-finished';
		$pid      = pcntl_fork();
		self::assertNotSame( -1, $pid );

		if ( 0 === $pid ) {
			fclose( $lock );
			try {
				file_put_contents( $ready, 'ready' );
				$this->secretsFile()->saveCredential(
					'gh',
					'exclusive_writer',
					array(
						'label'         => 'Exclusive writer',
						'kind'          => 'classic',
						'configuration' => array( 'owner' => '' ),
					),
					'sentinel-exclusive-writer-token'
				);
				file_put_contents( $finished, 'finished' );
				exit( 0 );
			} catch ( \Throwable ) {
				exit( 1 );
			}
		}

		$deadline = microtime( true ) + 2.0;
		while ( ! is_file( $ready ) && microtime( true ) < $deadline ) {
			usleep( 1000 );
		}
		self::assertFileExists( $ready );
		usleep( 50000 );
		self::assertFileDoesNotExist( $finished );
		self::assertSame( $before, file_get_contents( $this->path ) );

		self::assertTrue( flock( $lock, LOCK_UN ) );
		fclose( $lock );
		self::assertSame( $pid, pcntl_waitpid( $pid, $status ) );
		self::assertTrue( pcntl_wifexited( $status ) );
		self::assertSame( 0, pcntl_wexitstatus( $status ) );
		self::assertFileExists( $finished );
		self::assertSame(
			'sentinel-exclusive-writer-token',
			$this->secretsFile()->credentialMaterial( 'gh', 'exclusive_writer' )['secret']
		);
	}

	public function testRenameFailurePreservesThePreviousFileAndRemovesTheTemporaryFile(): void {
		$this->writeSidecar(
			array(
				'schema_version' => 2,
				'credentials'    => array(),
				'webhooks'       => array(),
			)
		);
		$before  = file_get_contents( $this->path );
		$secrets = new class(
			$this->path,
			array(),
			ShippedSecretPolicyCatalog::create(),
			$this->keyStore(),
			new EncryptedSecretsEnvelopeCodec()
		) extends SecretsFile {
			protected function replaceFile( string $source, string $destination ): bool {
				return false;
			}
		};

		try {
			$secrets->saveCredential(
				'gh',
				'gh_rename_failure',
				array(
					'label'         => 'Rename failure',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				self::GITHUB_TOKEN
			);
			self::fail( 'A failed atomic replacement must be reported.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( self::GITHUB_TOKEN, $exception->getMessage() );
		}

		self::assertSame( $before, file_get_contents( $this->path ) );
		self::assertSame( array(), glob( $this->directory . '/.ran-booster-*' ) );
	}

	public function testPostReplacementValidationFailureRestoresThePreviousCiphertext(): void {
		$this->writeSidecar(
			array(
				'schema_version' => 2,
				'credentials'    => array(),
				'webhooks'       => array(),
			)
		);
		$before  = file_get_contents( $this->path );
		$secrets = new class(
			$this->path,
			array(),
			ShippedSecretPolicyCatalog::create(),
			$this->keyStore(),
			new EncryptedSecretsEnvelopeCodec()
		) extends SecretsFile {
			private int $replacementCount = 0;

			protected function replaceFile( string $source, string $destination ): bool {
				$replaced = parent::replaceFile( $source, $destination );
				if ( $replaced && 1 === ++$this->replacementCount ) {
					file_put_contents( $destination, 'post-replacement-corruption' );
				}

				return $replaced;
			}
		};

		try {
			$secrets->saveCredential(
				'gh',
				'gh_post_replace_failure',
				array(
					'label'         => 'Post-replacement failure',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				self::GITHUB_TOKEN
			);
			self::fail( 'A failed post-replacement validation must be reported.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( self::GITHUB_TOKEN, $exception->getMessage() );
		}

		self::assertSame( $before, file_get_contents( $this->path ) );
		self::assertSame( array(), glob( $this->directory . '/.ran-booster-*' ) );
		self::assertSame( array(), $secrets->credentialProfiles( 'gh' ) );
	}

	public function testValidationFailureRedactsTheCredentialFromTheWholeTrace(): void {
		$canary  = 'validation-trace-secret-canary';
		$secrets = $this->secretsFile();

		try {
			$secrets->saveCredential(
				'gh',
				'gh_trace_validation',
				array(
					'label'         => 'Trace validation',
					'kind'          => 'unsupported-kind',
					'configuration' => array( 'owner' => '' ),
				),
				$canary
			);
			self::fail( 'Invalid credential material must be rejected.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( $canary, var_export( $exception->getTrace(), true ) );
		}
	}

	public function testWriteFailureRedactsTheCredentialFromTheWholeTrace(): void {
		$canary  = 'write-trace-secret-canary';
		$secrets = new class(
			$this->path,
			array(),
			ShippedSecretPolicyCatalog::create(),
			$this->keyStore(),
			new EncryptedSecretsEnvelopeCodec()
		) extends SecretsFile {
			protected function replaceFile( string $source, string $destination ): bool {
				return false;
			}
		};

		try {
			$secrets->saveCredential(
				'gh',
				'gh_trace_write',
				array(
					'label'         => 'Trace write',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				$canary
			);
			self::fail( 'A failed encrypted write must be reported.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( $canary, var_export( $exception->getTrace(), true ) );
		}
	}

	public function testMissingPosixOwnerIdentityFailsClosedBeforeCreatingManagedMaterial(): void {
		$secrets = new class(
			$this->path,
			array(),
			ShippedSecretPolicyCatalog::create(),
			$this->keyStore(),
			new EncryptedSecretsEnvelopeCodec()
		) extends SecretsFile {
			protected function effectiveUserId(): ?int {
				return null;
			}
		};

		try {
			$secrets->assertManagedStorageReady();
			self::fail( 'Storage must be unavailable when owner identity cannot be established.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( $this->directory, $exception->getMessage() );
		}

		self::assertFileDoesNotExist( $this->path );
		self::assertFileDoesNotExist( $this->path . '.lock' );
		self::assertFileDoesNotExist( $this->keyPath );
	}

	public function testFailedFirstWriteRemovesOnlyItsNewKeyAndLeavesNoCiphertext(): void {
		$secrets = new class(
			$this->path,
			array(),
			ShippedSecretPolicyCatalog::create(),
			$this->keyStore(),
			new EncryptedSecretsEnvelopeCodec()
		) extends SecretsFile {
			protected function replaceFile( string $source, string $destination ): bool {
				return false;
			}
		};

		try {
			$secrets->saveCredential(
				'gh',
				'first_write_failure',
				array(
					'label'         => 'First write failure',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				self::GITHUB_TOKEN
			);
			self::fail( 'The failed first write must be reported.' );
		} catch ( RuntimeException ) {
			self::assertFileDoesNotExist( $this->path );
			self::assertFileDoesNotExist( $this->keyPath );
			self::assertFileExists( $this->path . '.lock' );
			$this->expectException( RuntimeException::class );
			$secrets->hasHealthyManagedStorage();
		}
	}

	public function testTamperAndWrongKeyFailClosedWithoutRewritingCiphertext(): void {
		$this->writeSidecar( $this->validDocument() );
		$canonical             = (string) file_get_contents( $this->path );
		$decoded               = json_decode( $canonical, true, 4, JSON_THROW_ON_ERROR );
		$bytes                 = base64_decode( $decoded['ciphertext'], true );
		$bytes[0]              = chr( ord( $bytes[0] ) ^ 1 );
		$decoded['ciphertext'] = base64_encode( $bytes );
		$tampered              = json_encode( $decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n";
		self::assertNotFalse( file_put_contents( $this->path, $tampered ) );

		try {
			$this->secretsFile()->hasHealthyManagedStorage();
			self::fail( 'Tampered ciphertext must fail closed.' );
		} catch ( RuntimeException ) {
			self::assertSame( $tampered, file_get_contents( $this->path ) );
		}

		self::assertNotFalse( file_put_contents( $this->path, $canonical ) );
		self::assertNotFalse( file_put_contents( $this->keyPath, 'abcdefghijklmnopqrstuvwxyzABCDEF' ) );
		try {
			$this->secretsFile()->credentialProfiles( 'gh' );
			self::fail( 'A wrong site key must fail closed.' );
		} catch ( RuntimeException ) {
			self::assertSame( $canonical, file_get_contents( $this->path ) );
		}
	}

	public function testEveryKeyCiphertextHalfStateFailsClosed(): void {
		$this->keyStore()->loadOrCreate();
		$this->writeLock();
		try {
			$this->secretsFile()->assertManagedStorageReady();
			self::fail( 'A key without ciphertext must fail closed.' );
		} catch ( RuntimeException ) {
			self::assertFileDoesNotExist( $this->path );
		}

		self::assertTrue( unlink( $this->keyPath ) );
		$key        = SecretsFileFixtureKeyStore::KEY;
		$plaintext  = json_encode( $this->validDocument(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n";
		$ciphertext = ( new EncryptedSecretsEnvelopeCodec() )->encrypt( $plaintext, $key );
		self::assertNotFalse( file_put_contents( $this->path, $ciphertext ) );
		self::assertTrue( chmod( $this->path, 0600 ) );

		$this->expectException( RuntimeException::class );
		$this->secretsFile()->assertManagedStorageReady();
	}

	public function testExplicitConstantAndTemporaryCredentialsBypassABrokenManagedStore(): void {
		$this->keyStore()->loadOrCreate();
		$this->writeLock();
		self::assertNotFalse( file_put_contents( $this->path, 'broken-ciphertext' ) );
		self::assertTrue( chmod( $this->path, 0600 ) );
		$secrets = $this->secretsFile(
			array( 'RAN_BOOSTER_GITHUB_TOKEN' => self::GITHUB_TOKEN )
		);

		self::assertSame(
			self::GITHUB_TOKEN,
			$secrets->credentialMaterial( 'gh', SecretsFile::CONSTANT_PROFILE )['secret']
		);
		$secrets->withTemporaryCredential(
			'gh',
			array(
				'label'         => 'Temporary',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			'temporary-secret-canary',
			static function ( string $id ) use ( $secrets ): void {
				self::assertSame( 'temporary-secret-canary', $secrets->credentialMaterial( 'gh', $id )['secret'] );
			}
		);

		$this->expectException( RuntimeException::class );
		$secrets->credentialMaterial( 'gh', 'requested_file_profile' );
	}

	public function testPristineReadinessCheckDoesNotCreateStoreMaterial(): void {
		$secrets = $this->secretsFile();
		$secrets->assertManagedStorageReady();
		self::assertFalse( $secrets->hasHealthyManagedStorage() );

		self::assertFileDoesNotExist( $this->path );
		self::assertFileDoesNotExist( $this->path . '.lock' );
		self::assertFileDoesNotExist( $this->keyPath );
	}

	public function testSuccessfulWritesProduceNonExecutableCiphertextWithoutPlaintextCanaries(): void {
		$secrets = $this->secretsFile();

		$secrets->saveCredential(
			'gh',
			'gh_opcode_refresh',
			array(
				'label'         => 'Opcode refresh credential',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			self::GITHUB_TOKEN
		);
		$secrets->saveWebhook(
			'gh',
			'gh_opcode_refresh',
			array(
				'label'  => 'Opcode refresh webhook',
				'scope'  => 'global',
				'target' => '',
			),
			self::GITHUB_WEBHOOK_SECRET
		);

		$contents = (string) file_get_contents( $this->path );
		self::assertStringStartsWith( '{"format":"ran-booster-encrypted-secrets"', $contents );
		self::assertStringNotContainsString( '<?php', $contents );
		self::assertStringNotContainsString( self::GITHUB_TOKEN, $contents );
		self::assertStringNotContainsString( self::GITHUB_WEBHOOK_SECRET, $contents );
	}

	public function testTemporaryPermissionFailurePreservesThePreviousFile(): void {
		$this->writeSidecar(
			array(
				'schema_version' => 2,
				'credentials'    => array(),
				'webhooks'       => array(),
			)
		);
		$before  = file_get_contents( $this->path );
		$secrets = new class(
			$this->path,
			array(),
			ShippedSecretPolicyCatalog::create(),
			$this->keyStore(),
			new EncryptedSecretsEnvelopeCodec()
		) extends SecretsFile {
			protected function changePermissions( string $path, int $mode ): bool {
				if ( str_starts_with( basename( $path ), '.ran-booster-' ) ) {
					return false;
				}

				return parent::changePermissions( $path, $mode );
			}
		};

		try {
			$secrets->saveCredential(
				'gh',
				'gh_permission_failure',
				array(
					'label'         => 'Permission failure',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				self::GITHUB_TOKEN
			);
			self::fail( 'An insecure temporary file must be rejected.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( self::GITHUB_TOKEN, $exception->getMessage() );
		}

		self::assertSame( $before, file_get_contents( $this->path ) );
		self::assertSame( array(), glob( $this->directory . '/.ran-booster-*' ) );
	}

	public function testShortTemporaryWritesCompleteWithoutLosingExistingCredentials(): void {
		$secrets = $this->secretsFile();
		$secrets->saveCredential(
			'gh',
			'existing_short_write',
			array(
				'label'         => 'Existing short write',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			self::GITHUB_TOKEN
		);
		$partialWriter = new class(
			$this->path,
			array(),
			ShippedSecretPolicyCatalog::create(),
			$this->keyStore(),
			new EncryptedSecretsEnvelopeCodec()
		) extends SecretsFile {
			protected function writeHandle( mixed $handle, #[\SensitiveParameter] string $contents ): int|false {
				return parent::writeHandle( $handle, substr( $contents, 0, 7 ) );
			}
		};

		$partialWriter->saveCredential(
			'gh',
			'new_short_write',
			array(
				'label'         => 'New short write',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			'sentinel-short-write-token'
		);

		self::assertSame( self::GITHUB_TOKEN, $partialWriter->credentialMaterial( 'gh', 'existing_short_write' )['secret'] );
		self::assertSame( 'sentinel-short-write-token', $partialWriter->credentialMaterial( 'gh', 'new_short_write' )['secret'] );
		self::assertSame( array(), glob( $this->directory . '/.ran-booster-*' ) );
	}

	/**
	 * @param array<string, mixed> $constants
	 */
	private function secretsFile( array $constants = array() ): SecretsFile {
		return new SecretsFile(
			$this->path,
			$constants,
			ShippedSecretPolicyCatalog::create(),
			$this->keyStore(),
			new EncryptedSecretsEnvelopeCodec()
		);
	}

	private function keyStore(): SiteKeyStore {
		return new SecretsFileFixtureKeyStore( $this->keyPath );
	}

	private function assertConfiguredPathRejectedWithoutLeak( SecretsFile $secrets ): void {
		try {
			$secrets->saveCredential(
				'gh',
				'unsafe_first_write',
				array(
					'label'         => 'Unsafe first write',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				self::GITHUB_TOKEN
			);
			self::fail( 'An unsafe configured location must be rejected.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( $this->directory, $exception->getMessage() );
		}

		self::assertFileDoesNotExist( $this->keyPath );
		self::assertFileDoesNotExist( (string) $secrets->path() );
		self::assertFileDoesNotExist( (string) $secrets->path() . '.lock' );
	}

	private function removeFixturePath( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}

		$entries = scandir( $path );
		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->removeFixturePath( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}

	private function githubBlueprintCredential(): BlueprintCredential {
		return new BlueprintCredential(
			'gh',
			'Imported GitHub token',
			'classic',
			array( 'owner' => '' ),
			self::GITHUB_TOKEN,
			array( $this->identity( 'plugin', 'example/example.php' ) )
		);
	}

	private function blueprint( BlueprintCredential $credential ): PackageBlueprint {
		return new PackageBlueprint(
			array( $this->package( 'plugin', 'example/example.php', 'Example Plugin', $credential->provider ) ),
			array( $credential )
		);
	}

	/** @return array{type:string,identifier:string} */
	private function identity( string $type, string $identifier ): array {
		return array(
			'type'       => $type,
			'identifier' => $identifier,
		);
	}

	private function package( string $type, string $identifier, string $displayName, string $provider ): BlueprintPackage {
		return new BlueprintPackage(
			$type,
			$identifier,
			$displayName,
			$provider,
			$provider . '-repository-id',
			'owner/repository',
			'main',
			null
		);
	}

	/**
	 * @param array<string, mixed> $contents
	 */
	private function writeSidecar( array $contents, ?string $path = null ): void {
		$path      = $path ?? $this->path;
		$keyStore  = $this->keyStore();
		$key       = $keyStore->loadOrCreate()['key'];
		$plaintext = json_encode(
			$contents,
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . "\n";
		self::assertNotFalse(
			file_put_contents(
				$path,
				( new EncryptedSecretsEnvelopeCodec() )->encrypt( $plaintext, $key )
			)
		);
		self::assertTrue( chmod( $path, 0600 ) );
		if ( ! file_exists( $this->path . '.lock' ) ) {
			self::assertNotFalse( file_put_contents( $this->path . '.lock', '' ) );
			self::assertTrue( chmod( $this->path . '.lock', 0600 ) );
		}
	}

	/** @return array<string, mixed> */
	private function validDocument(): array {
		return array(
			'schema_version' => SecretsFile::SCHEMA_VERSION,
			'credentials'    => array(),
			'webhooks'       => array(),
		);
	}

	private function writeLock(): void {
		if ( ! file_exists( $this->path . '.lock' ) ) {
			self::assertNotFalse( file_put_contents( $this->path . '.lock', '' ) );
			self::assertTrue( chmod( $this->path . '.lock', 0600 ) );
		}
	}
}

/**
 * File-backed test double models one WordPress option across forked processes.
 */
final class SecretsFileFixtureKeyStore extends SiteKeyStore {

	public const KEY = '12345678901234567890123456789012';

	public function __construct( private string $path ) {
	}

	public function load( bool $repairAutoload = true ): ?string {
		if ( ! file_exists( $this->path ) ) {
			return null;
		}

		$key = file_get_contents( $this->path );
		if ( ! is_string( $key ) || 32 !== strlen( $key ) ) {
			throw new RuntimeException( 'The fixture key is invalid.' );
		}

		return $key;
	}

	public function loadOrCreate(): array {
		$key = $this->load();
		if ( null !== $key ) {
			return array(
				'key'     => $key,
				'created' => false,
			);
		}

		if ( false === file_put_contents( $this->path, self::KEY, LOCK_EX )
			|| ! chmod( $this->path, 0600 )
		) {
			throw new RuntimeException( 'The fixture key could not be stored.' );
		}

		return array(
			'key'     => self::KEY,
			'created' => true,
		);
	}

	public function deleteExact( #[\SensitiveParameter] string $key ): bool {
		$stored = $this->load();
		if ( null === $stored || ! hash_equals( $stored, $key ) ) {
			return false;
		}

		return unlink( $this->path );
	}
}
