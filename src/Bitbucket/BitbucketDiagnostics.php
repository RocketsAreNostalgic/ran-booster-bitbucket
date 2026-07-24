<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use RAN\Logging\BoosterLogger;
use RAN\RepositoryProvider\ProviderDiagnosticBudgetExceeded;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RuntimeException;

final readonly class BitbucketDiagnostics implements ProviderDiagnostics {

	public function __construct(
		private BitbucketCredentialValidator $credentials,
		private BitbucketRepositoryBrowser $browser
	) {
	}

	public function diagnose( ProviderDiagnosticRequest $request ): array {
		return array(
			$this->credentialResult( $request ),
			$this->repositoryResult( $request ),
		);
	}

	private function credentialResult( ProviderDiagnosticRequest $request ): ProviderDiagnosticResult {
		$credentialId = $request->getCredentialId();
		if ( null === $credentialId ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::NOT_CONFIGURED,
				'bb.credential.not_configured',
				'No Bitbucket credential was selected.',
				'Select a credential to verify access to private repositories.'
			);
		}

		try {
			$result = $this->credentials->validateCredential( $credentialId, $request->claimRemoteCall(), 65536 );
		} catch ( ProviderDiagnosticBudgetExceeded ) {
			return $this->budgetResult( 'bb.credential.budget_exhausted' );
		} catch ( \Throwable $exception ) {
			BoosterLogger::logException( 'Bitbucket diagnostics credential check failed', $exception, array( 'step' => 'bb_credential_diagnostics' ) );
			return $this->unavailableResult( 'bb.credential.unavailable', 'Bitbucket credential validation could not be completed.' );
		}

		if ( $result->isValid() ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::PASSED,
				'bb.credential.valid',
				'Bitbucket accepted the selected credential.',
				'No action is needed.'
			);
		}

		if ( \RAN\RepositoryProvider\CredentialValidationResult::RATE_LIMITED === $result->reason ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::WARNING,
				'bb.credential.rate_limited',
				'Bitbucket rate-limited credential validation.',
				'Try the check again after the rate limit resets.'
			);
		}

		if ( in_array(
			$result->reason,
			array(
				\RAN\RepositoryProvider\CredentialValidationResult::UNAVAILABLE,
				\RAN\RepositoryProvider\CredentialValidationResult::INVALID_RESPONSE,
			),
			true
		) ) {
			return $this->unavailableResult( 'bb.credential.unavailable', 'Bitbucket credential validation could not be completed.' );
		}

		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::FAILED,
			'bb.credential.invalid',
			'Bitbucket did not accept the selected credential.',
			'Check the workspace, account email, API token, scope, and expiry.'
		);
	}

	private function repositoryResult( ProviderDiagnosticRequest $request ): ProviderDiagnosticResult {
		$repository = $request->getRepository();
		if ( null === $repository ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::NOT_CONFIGURED,
				'bb.repository.not_configured',
				'No Bitbucket repository was selected for the reachability check.',
				'Select a repository to verify its visibility and scope.'
			);
		}

		try {
			$this->browser->repository( $repository, $request->getCredentialId(), $request->claimRemoteCall(), 65536 );
		} catch ( ProviderDiagnosticBudgetExceeded ) {
			return $this->budgetResult( 'bb.repository.budget_exhausted' );
		} catch ( RuntimeException $exception ) {
			return $this->repositoryFailure( $exception );
		} catch ( \Throwable $exception ) {
			BoosterLogger::logException( 'Bitbucket diagnostics repository check failed', $exception, array( 'step' => 'bb_repository_diagnostics' ) );
			return $this->unavailableResult( 'bb.repository.unavailable', 'Bitbucket repository access could not be completed.' );
		}

		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::PASSED,
			'bb.repository.reachable',
			'Bitbucket returned the selected repository.',
			'No action is needed.'
		);
	}

	private function repositoryFailure( RuntimeException $exception ): ProviderDiagnosticResult {
		return match ( $exception->getCode() ) {
			401, 403 => new ProviderDiagnosticResult(
				ProviderDiagnosticResult::FAILED,
				'bb.repository.denied',
				'Bitbucket denied access to the selected repository.',
				'Check the API token scope and workspace access.'
			),
			404 => new ProviderDiagnosticResult(
				ProviderDiagnosticResult::FAILED,
				'bb.repository.not_found',
				'Bitbucket could not find the selected repository with this credential.',
				'Check the repository name, workspace, and credential access.'
			),
			429 => new ProviderDiagnosticResult(
				ProviderDiagnosticResult::WARNING,
				'bb.repository.rate_limited',
				'Bitbucket rate-limited the repository check.',
				'Try the check again after the rate limit resets.'
			),
			default => $this->unavailableResult( 'bb.repository.unavailable', 'Bitbucket repository access could not be completed.' ),
		};
	}

	private function budgetResult( string $code ): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::WARNING,
			$code,
			'This Bitbucket check was not run because the diagnostic budget was exhausted.',
			'Run diagnostics again after other provider requests have completed.'
		);
	}

	private function unavailableResult( string $code, string $message ): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::WARNING,
			$code,
			$message,
			'Try again and check Bitbucket service status if the problem continues.'
		);
	}
}
