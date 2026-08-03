<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$ran_booster_bitbucket_settings_url       = admin_url( 'admin.php?page=ran-booster&tab=bb' );
$ran_booster_bitbucket_install_plugin_url = admin_url( 'admin.php?page=ran-booster-plugins-create' );
$ran_booster_bitbucket_install_theme_url  = admin_url( 'admin.php?page=ran-booster-themes-create' );
$ran_booster_bitbucket_support_url        = 'https://github.com/RocketsAreNostalgic/ran-booster-bitbucket/issues';

?>
		<p><?php esc_html_e( 'The Bitbucket Cloud add-on connects RAN Booster to public and private Bitbucket repositories. It supplies repository discovery, archive downloads, credential checks, signed webhook handling and provider diagnostics while Core remains responsible for package records, secret custody, deployment policy and deployment execution.', 'ran-booster-bitbucket' ); ?></p>
		<p><?php esc_html_e( 'This is trusted credential-bearing provider code. Core binds the provider to bb and supplies only a selected Bitbucket credential during an authorized provider operation. The add-on does not enumerate other provider credentials, store the plaintext or receive Core’s sidecar, key or container. This supported boundary is not confidentiality from hostile PHP running in the same WordPress process.', 'ran-booster-bitbucket' ); ?></p>

		<h3><?php esc_html_e( 'Create the narrowest API token', 'ran-booster-bitbucket' ); ?></h3>
		<p><?php esc_html_e( 'Public repositories do not require a token. For private repositories, create a dedicated, expiring Bitbucket Cloud API token for the Atlassian account that can access the intended workspace. Give it only Repositories: Read (read:repository:bitbucket). Booster does not need Webhooks, Pull requests, Projects, Pipelines, Runners, Issues, SSH keys, or any Write, Admin or Delete permission.', 'ran-booster-bitbucket' ); ?></p>
		<p><?php esc_html_e( 'Save the token together with the Atlassian account email and exact workspace slug. App passwords and workspace, project or repository access tokens are not supported. Choose an expiry that matches your operating policy, record it outside WordPress, and replace the saved credential before it expires.', 'ran-booster-bitbucket' ); ?></p>
		<ul>
			<li><a href="https://support.atlassian.com/bitbucket-cloud/docs/create-an-api-token/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Create a Bitbucket Cloud API token', 'ran-booster-bitbucket' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster-bitbucket' ); ?></span></a></li>
			<li><a href="https://support.atlassian.com/bitbucket-cloud/docs/api-token-permissions/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Review Bitbucket API token permissions', 'ran-booster-bitbucket' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster-bitbucket' ); ?></span></a></li>
		</ul>
		<p><a class="button" href="<?php echo esc_url( $ran_booster_bitbucket_settings_url ); ?>"><?php esc_html_e( 'Open Bitbucket settings', 'ran-booster-bitbucket' ); ?></a></p>

		<h3><?php esc_html_e( 'Connect a package', 'ran-booster-bitbucket' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Open Install Plugin or Install Theme, select Bitbucket, then browse with an eligible saved credential or enter the workspace/repository address manually.', 'ran-booster-bitbucket' ); ?></li>
			<li><?php esc_html_e( 'Confirm the resolved repository, branch and optional package subdirectory. Select a saved credential only when anonymous access is insufficient.', 'ran-booster-bitbucket' ); ?></li>
			<li><?php esc_html_e( 'Install the package and verify it in WordPress. New and recovered packages start with deployment Disabled; keep that policy until manual updates and any webhook delivery have been tested.', 'ran-booster-bitbucket' ); ?></li>
		</ol>
		<p>
			<a href="<?php echo esc_url( $ran_booster_bitbucket_install_plugin_url ); ?>"><?php esc_html_e( 'Install a plugin', 'ran-booster-bitbucket' ); ?></a>
			<span aria-hidden="true"> · </span>
			<a href="<?php echo esc_url( $ran_booster_bitbucket_install_theme_url ); ?>"><?php esc_html_e( 'Install a theme', 'ran-booster-bitbucket' ); ?></a>
		</p>
		<p><?php esc_html_e( 'For configuration-managed installations, define RAN_BOOSTER_BITBUCKET_WORKSPACE, RAN_BOOSTER_BITBUCKET_EMAIL and RAN_BOOSTER_BITBUCKET_TOKEN together. Constants are an alternative credential source; do not duplicate the same credential in the administrator screen.', 'ran-booster-bitbucket' ); ?></p>

		<h3><?php esc_html_e( 'Set up Push-to-Deploy manually', 'ran-booster-bitbucket' ); ?></h3>
		<p><?php esc_html_e( 'This add-on verifies Bitbucket webhooks but does not create or remove them at Bitbucket. In the Bitbucket provider screen, create a workspace-scoped or repository-scoped webhook secret and copy it when Core presents it. A repository secret gives the narrowest isolation; a workspace secret can be reused only for repositories in that workspace.', 'ran-booster-bitbucket' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'In Bitbucket, open Repository settings, then Webhooks, then Add webhook.', 'ran-booster-bitbucket' ); ?></li>
			<li><?php esc_html_e( 'Use the payload URL shown by the Bitbucket provider screen, keep SSL verification enabled, select the Repository push event and paste the same secret.', 'ran-booster-bitbucket' ); ?></li>
			<li><?php esc_html_e( 'Save the remote hook, send a test delivery or push a harmless commit, and confirm both Bitbucket delivery history and Booster Deployment activity report the expected result.', 'ran-booster-bitbucket' ); ?></li>
			<li><?php esc_html_e( 'Only after the site is reachable over public HTTPS and the signed delivery succeeds should you deliberately change the intended package to Automatic.', 'ran-booster-bitbucket' ); ?></li>
		</ol>
		<p><?php esc_html_e( 'A saved local secret proves only that Core can verify a matching signature. It does not prove that the remote Bitbucket webhook exists, is enabled or contains the same secret.', 'ran-booster-bitbucket' ); ?></p>
		<ul>
			<li><a href="https://support.atlassian.com/bitbucket-cloud/docs/manage-webhooks/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage Bitbucket webhooks', 'ran-booster-bitbucket' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster-bitbucket' ); ?></span></a></li>
			<li><a href="https://support.atlassian.com/bitbucket-cloud/docs/troubleshoot-webhooks/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Troubleshoot Bitbucket webhooks', 'ran-booster-bitbucket' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster-bitbucket' ); ?></span></a></li>
		</ul>

		<h3><?php esc_html_e( 'Move or recover a package with Transporter', 'ran-booster-bitbucket' ); ?></h3>
		<p><?php esc_html_e( 'A Transporter Blueprint records the selected package and Bitbucket repository configuration. When explicitly included, it can carry a file-stored repository credential inside the password-protected Blueprint. The target site revalidates repository identity and access before applying it and re-encrypts accepted credentials with that site’s Core key.', 'ran-booster-bitbucket' ); ?></p>
		<p><?php esc_html_e( 'Blueprints do not carry webhook secrets, provider-side webhooks, deployment history, locks or source deployment policy. Recreate and test the Bitbucket webhook on the target site, then choose its deployment policy deliberately. Keep the normal database and filesystem backup as the recovery source for the complete WordPress site.', 'ran-booster-bitbucket' ); ?></p>

		<h3><?php esc_html_e( 'Deactivation, deletion and provider cleanup', 'ran-booster-bitbucket' ); ?></h3>
		<p><?php esc_html_e( 'Deactivating this add-on stops the bb provider from registering. Core keeps the managed package records, credentials, webhook secrets and deployment history unchanged, but Bitbucket packages remain unavailable until a compatible add-on is active again. Reactivation reconnects those records through their stored provider and stable repository identity.', 'ran-booster-bitbucket' ); ?></p>
		<p><?php esc_html_e( 'Deleting the add-on removes only its plugin files. It does not revoke Bitbucket API tokens, remove remote webhooks or delete Core-owned records. Before permanent retirement, remove the remote hooks in Bitbucket, revoke the API token at Atlassian, and follow Core’s documented package, credential and uninstall procedures.', 'ran-booster-bitbucket' ); ?></p>

		<h3><?php esc_html_e( 'Private releases and support', 'ran-booster-bitbucket' ); ?></h3>
		<p><?php esc_html_e( 'Updates are supplied as verified private GitHub release ZIPs with a matching SHA-256 checksum. Generated source archives are not distributable WordPress packages. Losing future private-release access does not deactivate or restrict a GPL copy already installed on this site.', 'ran-booster-bitbucket' ); ?></p>
		<p><?php esc_html_e( 'For ordinary, non-sensitive support, use the private repository’s GitHub issue tracker. Never post API tokens, account email addresses, webhook secrets, signed URLs, private release assets or customer data in an issue. For a suspected security vulnerability, use the confidential contact supplied with the private release; if no confidential route is available, request one without disclosing the sensitive details.', 'ran-booster-bitbucket' ); ?></p>
		<p><a href="<?php echo esc_url( $ran_booster_bitbucket_support_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Bitbucket add-on support', 'ran-booster-bitbucket' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster-bitbucket' ); ?></span></a></p>
