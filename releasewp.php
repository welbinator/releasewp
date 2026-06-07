<?php
/**
 * Plugin Name: ReleaseWP
 * Description: Handles posting changelog updates from GitHub to a custom post type in WordPress.
 * Version: 1.1.0
 * Author: James Welbes
 *
 * @package ReleaseWP
 */

namespace ReleaseWP;

defined( 'ABSPATH' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'Parsedown.php';

// Register settings page.
add_action( 'admin_menu', __NAMESPACE__ . '\\register_settings_page' );
add_action( 'admin_init', __NAMESPACE__ . '\\register_settings' );

// AJAX: generate and save a webhook secret.
add_action( 'wp_ajax_releasewp_generate_secret', __NAMESPACE__ . '\\ajax_generate_secret' );

/**
 * Register the settings page in WordPress admin menu.
 *
 * @return void
 */
function register_settings_page(): void {
	add_options_page(
		'ReleaseWP Settings',
		'ReleaseWP',
		'manage_options',
		'releasewp-settings',
		__NAMESPACE__ . '\\render_settings_page'
	);
}

/**
 * Register plugin settings and settings fields.
 *
 * @return void
 */
function register_settings(): void {
	register_setting(
		'releasewp_settings',
		'releasewp_post_type',
		array(
			'type'              => 'string',
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize_post_type_option',
			'default'           => 'post',
		)
	);

	register_setting(
		'releasewp_settings',
		'releasewp_title_template',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'Version %version%',
		)
	);

	register_setting(
		'releasewp_settings',
		'releasewp_webhook_secret',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);

	add_settings_section(
		'releasewp_main_section',
		'Post Type Settings',
		'__return_null',
		'releasewp-settings'
	);

	add_settings_section(
		'releasewp_security_section',
		'Webhook Security',
		__NAMESPACE__ . '\\render_security_section_description',
		'releasewp-settings'
	);

	add_settings_field(
		'releasewp_post_type',
		'Target Post Type',
		__NAMESPACE__ . '\\render_post_type_field',
		'releasewp-settings',
		'releasewp_main_section'
	);

	add_settings_field(
		'releasewp_title_template',
		'Title Template',
		__NAMESPACE__ . '\\render_title_template_field',
		'releasewp-settings',
		'releasewp_main_section'
	);

	add_settings_field(
		'releasewp_webhook_secret',
		'Webhook Secret',
		__NAMESPACE__ . '\\render_webhook_secret_field',
		'releasewp-settings',
		'releasewp_security_section'
	);
}

/**
 * Sanitize the post type option — only allow publicly registered post types.
 *
 * @param mixed $value The submitted value.
 * @return string A valid registered public post type slug, or 'post' as fallback.
 */
function sanitize_post_type_option( $value ): string {
	$value      = sanitize_key( (string) $value );
	$post_types = get_post_types( array( 'public' => true ) );
	if ( in_array( $value, $post_types, true ) ) {
		return $value;
	}
	return 'post';
}

/**
 * AJAX handler: generate a cryptographically secure webhook secret, save it,
 * and return it to the browser so the Setup Guide can display it immediately.
 *
 * @return void
 */
function ajax_generate_secret(): void {
	check_ajax_referer( 'releasewp_generate_secret', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}

	$secret = bin2hex( random_bytes( 32 ) );
	update_option( 'releasewp_webhook_secret', $secret );

	wp_send_json_success( array( 'secret' => $secret ) );
}

/**
 * Render a description for the security settings section.
 *
 * @return void
 */
function render_security_section_description(): void {
	echo '<p>' . esc_html__( 'Configure the shared secret used to verify that incoming webhook requests originate from GitHub.', 'releasewp' ) . '</p>';
}

/**
 * Render the post type selection field.
 *
 * @return void
 */
function render_post_type_field(): void {
	$selected_post_type = sanitize_key( (string) get_option( 'releasewp_post_type', 'post' ) );
	$post_types         = get_post_types( array( 'public' => true ), 'objects' );

	echo '<select name="releasewp_post_type" id="releasewp_post_type">';
	foreach ( $post_types as $post_type ) {
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( $post_type->name ),
			selected( $selected_post_type, $post_type->name, false ),
			esc_html( $post_type->label )
		);
	}
	echo '</select>';
	echo '<p class="description">' . esc_html__( 'Select the post type where changelog updates will be published.', 'releasewp' ) . '</p>';
}

/**
 * Render the title template input field.
 *
 * @return void
 */
function render_title_template_field(): void {
	$template = sanitize_text_field( (string) get_option( 'releasewp_title_template', 'Version %version%' ) );
	?>
	<input type="text"
		name="releasewp_title_template"
		id="releasewp_title_template"
		value="<?php echo esc_attr( $template ); ?>"
		class="regular-text"
		placeholder="e.g., MyPlugin %version% is here!">
	<p class="description">
		<?php esc_html_e( 'Use %version% as a placeholder for the version number sent from GitHub.', 'releasewp' ); ?><br>
		<?php esc_html_e( 'Examples:', 'releasewp' ); ?>
		<code>MyPlugin %version% is here!</code> <?php esc_html_e( 'or', 'releasewp' ); ?>
		<code>Version %version% Released</code>
	</p>
	<?php
}

/**
 * Render the webhook secret input field.
 *
 * @return void
 */
function render_webhook_secret_field(): void {
	$secret = (string) get_option( 'releasewp_webhook_secret', '' );
	?>
	<input type="password"
		name="releasewp_webhook_secret"
		id="releasewp_webhook_secret"
		value="<?php echo esc_attr( $secret ); ?>"
		class="regular-text"
		autocomplete="new-password">
	<p class="description">
		<?php esc_html_e( 'Paste the same secret you entered in your GitHub webhook settings. Requests without a valid HMAC-SHA256 signature will be rejected.', 'releasewp' ); ?>
		<br><strong><?php esc_html_e( 'Endpoint URL:', 'releasewp' ); ?></strong>
		<code><?php echo esc_url( rest_url( 'releasewp/v1/post-update' ) ); ?></code>
	</p>
	<?php
}

/**
 * Get the current admin tab, validated against allowed values.
 *
 * @return string The active tab slug: 'setup' or 'settings'.
 */
function get_active_tab(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab param controls display only; no data is processed.
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'setup';
	return in_array( $tab, array( 'setup', 'settings' ), true ) ? $tab : 'setup';
}

/**
 * Render the settings page with tabbed navigation.
 *
 * @return void
 */
function render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$active_tab   = get_active_tab();
	$settings_url = admin_url( 'options-general.php?page=releasewp-settings' );
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<nav class="nav-tab-wrapper">
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'setup', $settings_url ) ); ?>"
				class="nav-tab<?php echo 'setup' === $active_tab ? ' nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Setup Guide', 'releasewp' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $settings_url ) ); ?>"
				class="nav-tab<?php echo 'settings' === $active_tab ? ' nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Settings', 'releasewp' ); ?>
			</a>
		</nav>

		<?php if ( 'setup' === $active_tab ) : ?>
			<?php render_setup_tab(); ?>
		<?php else : ?>
			<?php render_settings_tab(); ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render the Settings tab — the options form.
 *
 * @return void
 */
function render_settings_tab(): void {
	?>
	<form action="options.php" method="post" style="margin-top: 20px;">
		<?php
		settings_fields( 'releasewp_settings' );
		do_settings_sections( 'releasewp-settings' );
		submit_button( 'Save Settings' );
		?>
	</form>
	<?php
}

/**
 * Render the Setup Guide tab — step-by-step instructions for connecting GitHub.
 *
 * @return void
 */
function render_setup_tab(): void {
	$endpoint_url = rest_url( 'releasewp/v1/post-update' );
	$settings_url = admin_url( 'options-general.php?page=releasewp-settings&tab=settings' );
	$has_secret   = '' !== (string) get_option( 'releasewp_webhook_secret', '' );

	// Nowdoc: no PHP interpolation — ${{ }}, $VARS, backslashes, and quotes are all literal.
	$workflow_yaml = <<<'YAML'
name: Post Release to WordPress

on:
  release:
    types: [published]

jobs:
  post-to-wordpress:
    runs-on: ubuntu-latest
    steps:
      - name: Send release notes to WordPress
        env:
          RELEASEWP_SECRET: ${{ secrets.RELEASEWP_SECRET }}
          RELEASEWP_ENDPOINT: ${{ secrets.RELEASEWP_ENDPOINT }}
          RELEASE_TAG: ${{ github.event.release.tag_name }}
          RELEASE_BODY: ${{ github.event.release.body }}
        run: |
          # Write the JSON payload to a file so the signature covers the exact bytes sent
          jq -n \
            --arg title "$RELEASE_TAG" \
            --arg content "$RELEASE_BODY" \
            '{title: $title, content: $content}' > /tmp/payload.json

          # Compute HMAC-SHA256 signature over the payload file
          SIGNATURE="sha256=$(openssl dgst -sha256 -hmac "$RELEASEWP_SECRET" /tmp/payload.json | awk '{print $NF}')"

          # POST to WordPress with the signature header
          curl -sf -X POST "$RELEASEWP_ENDPOINT" \
            -H "Content-Type: application/json" \
            -H "X-Hub-Signature-256: $SIGNATURE" \
            -d @/tmp/payload.json
YAML;

	?>
	<style>
	.rwp-overview {
		background: #fff;
		border: 1px solid #c3c4c7;
		border-left: 4px solid #2271b1;
		border-radius: 4px;
		padding: 16px 20px;
		margin: 20px 0;
	}
	.rwp-overview p { margin: 6px 0; font-size: 13px; }
	.rwp-steps { margin-top: 4px; }
	.rwp-step {
		background: #fff;
		border: 1px solid #c3c4c7;
		border-radius: 4px;
		padding: 20px 24px;
		margin-bottom: 14px;
	}
	.rwp-step h3 {
		margin: 0 0 10px;
		display: flex;
		align-items: center;
		gap: 10px;
		font-size: 14px;
	}
	.rwp-num {
		background: #2271b1;
		color: #fff;
		border-radius: 50%;
		min-width: 26px;
		height: 26px;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		font-size: 12px;
		font-weight: 700;
		flex-shrink: 0;
	}
	.rwp-step p { margin: 6px 0; font-size: 13px; }
	.rwp-code {
		background: #1e1e1e;
		color: #d4d4d4;
		border-radius: 4px;
		padding: 14px 16px;
		font-family: Consolas, 'Courier New', monospace;
		font-size: 12px;
		line-height: 1.65;
		overflow-x: auto;
		margin: 10px 0 4px;
		white-space: pre;
	}
	.rwp-ic {
		font-family: Consolas, 'Courier New', monospace;
		font-size: 12px;
		background: #f0f0f1;
		padding: 1px 5px;
		border-radius: 3px;
		word-break: break-all;
	}
	.rwp-tip {
		background: #f0f6fc;
		border-left: 4px solid #2271b1;
		padding: 9px 13px;
		margin: 10px 0 0;
		font-size: 12.5px;
	}
	.rwp-warn {
		background: #fcf9e8;
		border-left: 4px solid #dba617;
		padding: 9px 13px;
		margin: 10px 0 0;
		font-size: 12.5px;
	}
	.rwp-done { color: #00a32a; font-size: 12px; font-weight: 600; margin-left: 4px; }
	</style>

	<div class="rwp-overview">
		<p><strong><?php esc_html_e( 'How ReleaseWP works', 'releasewp' ); ?></strong></p>
		<p><?php esc_html_e( 'When you publish a release on GitHub, a GitHub Actions workflow automatically sends the release title and notes to this WordPress site. ReleaseWP receives the request and creates a new post — no manual copy-pasting needed.', 'releasewp' ); ?></p>
		<p><?php esc_html_e( 'The connection is secured with a shared secret. GitHub signs each outgoing request using that secret; WordPress verifies the signature and rejects anything that does not match. Only your GitHub repository can create posts through this endpoint.', 'releasewp' ); ?></p>
	</div>

	<div class="rwp-steps">

		<div class="rwp-step">
			<h3><span class="rwp-num">1</span><?php esc_html_e( 'Generate a webhook secret', 'releasewp' ); ?></h3>
			<p><?php esc_html_e( 'Click the button below to generate a secure secret and save it to WordPress automatically. You will need to copy it into your GitHub repository secrets in Step 3.', 'releasewp' ); ?></p>
			<div style="display:flex; align-items:center; gap:10px; margin:12px 0 4px;">
				<button type="button" id="rwp-generate-btn" class="button button-primary">
					<?php esc_html_e( 'Generate Secret', 'releasewp' ); ?>
				</button>
				<span id="rwp-generate-spinner" class="spinner" style="float:none; margin:0; visibility:hidden;"></span>
			</div>
			<div id="rwp-secret-result" style="display:none; margin-top:10px;">
				<p style="margin:0 0 6px;"><strong><?php esc_html_e( 'Your secret (copy this now):', 'releasewp' ); ?></strong></p>
				<div style="display:flex; align-items:center; gap:8px;">
					<code id="rwp-secret-value" class="rwp-ic" style="font-size:13px; padding:6px 10px; user-select:all;"></code>
					<button type="button" id="rwp-copy-btn" class="button">
						<?php esc_html_e( 'Copy', 'releasewp' ); ?>
					</button>
				</div>
				<div class="rwp-tip" style="margin-top:8px;"><?php esc_html_e( 'This secret has been saved to WordPress. Keep a copy of it — you will paste it into GitHub in Step 3.', 'releasewp' ); ?></div>
			</div>
			<div id="rwp-generate-error" class="rwp-warn" style="display:none; margin-top:10px;"></div>
			<script>
			(function() {
				var btn      = document.getElementById('rwp-generate-btn');
				var spinner  = document.getElementById('rwp-generate-spinner');
				var result   = document.getElementById('rwp-secret-result');
				var valueEl  = document.getElementById('rwp-secret-value');
				var copyBtn  = document.getElementById('rwp-copy-btn');
				var errorEl  = document.getElementById('rwp-generate-error');
				var step2num = document.getElementById('rwp-step2-status');

				btn.addEventListener('click', function() {
					btn.disabled    = true;
					spinner.style.visibility = 'visible';
					errorEl.style.display    = 'none';
					result.style.display     = 'none';

					var data = new FormData();
					data.append('action', 'releasewp_generate_secret');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'releasewp_generate_secret' ) ); ?>);

					fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
						method: 'POST',
						credentials: 'same-origin',
						body: data,
					})
					.then(function(r) { return r.json(); })
					.then(function(r) {
						if (r.success) {
							valueEl.textContent      = r.data.secret;
							result.style.display     = 'block';
							if (step2num) { step2num.style.display = 'inline'; }
							var warn = document.getElementById('rwp-step2-warn');
							if (warn) { warn.style.display = 'none'; }
						} else {
							errorEl.textContent      = r.data && r.data.message ? r.data.message : '<?php echo esc_js( __( 'An error occurred. Please try again.', 'releasewp' ) ); ?>';
							errorEl.style.display    = 'block';
						}
					})
					.catch(function() {
						errorEl.textContent   = '<?php echo esc_js( __( 'Request failed. Please check your connection and try again.', 'releasewp' ) ); ?>';
						errorEl.style.display = 'block';
					})
					.finally(function() {
						btn.disabled = false;
						spinner.style.visibility = 'hidden';
					});
				});

				copyBtn.addEventListener('click', function() {
					navigator.clipboard.writeText(valueEl.textContent).then(function() {
						var orig = copyBtn.textContent;
						copyBtn.textContent = '<?php echo esc_js( __( 'Copied!', 'releasewp' ) ); ?>';
						setTimeout(function() { copyBtn.textContent = orig; }, 2000);
					});
				});
			}());
			</script>
		</div>

		<div class="rwp-step">
			<h3>
				<span class="rwp-num">2</span>
				<?php esc_html_e( 'Save the secret in WordPress', 'releasewp' ); ?>
				<?php if ( $has_secret ) : ?>
					<span class="rwp-done" id="rwp-step2-status">&#10003; <?php esc_html_e( 'Done', 'releasewp' ); ?></span>
				<?php else : ?>
					<span class="rwp-done" id="rwp-step2-status" style="display:none;">&#10003; <?php esc_html_e( 'Done', 'releasewp' ); ?></span>
				<?php endif; ?>
			</h3>
			<p><?php esc_html_e( 'Clicking Generate Secret in Step 1 saves the secret to WordPress automatically. You can also set it manually on the', 'releasewp' ); ?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings tab', 'releasewp' ); ?></a>.
			</p>
			<?php if ( ! $has_secret ) : ?>
				<div class="rwp-warn" id="rwp-step2-warn"><?php esc_html_e( 'No secret saved yet. The endpoint will reject all requests until a secret is configured.', 'releasewp' ); ?></div>
			<?php endif; ?>
		</div>

		<div class="rwp-step">
			<h3><span class="rwp-num">3</span><?php esc_html_e( 'Add secrets to your GitHub repository', 'releasewp' ); ?></h3>
			<p><?php esc_html_e( 'In your GitHub repository, go to Settings &#8594; Secrets and variables &#8594; Actions &#8594; New repository secret. Add both of these:', 'releasewp' ); ?></p>
			<table class="widefat striped" style="margin-top: 12px; max-width: 640px;">
				<thead>
					<tr>
						<th style="width: 220px;"><?php esc_html_e( 'Secret name', 'releasewp' ); ?></th>
						<th><?php esc_html_e( 'Value', 'releasewp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><code>RELEASEWP_SECRET</code></td>
						<td><?php esc_html_e( 'The secret string you generated in Step 1', 'releasewp' ); ?></td>
					</tr>
					<tr>
						<td><code>RELEASEWP_ENDPOINT</code></td>
						<td><span class="rwp-ic"><?php echo esc_html( $endpoint_url ); ?></span></td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="rwp-step">
			<h3><span class="rwp-num">4</span><?php esc_html_e( 'Add the GitHub Actions workflow to your repository', 'releasewp' ); ?></h3>
			<p>
				<?php esc_html_e( 'Create this file in your repository:', 'releasewp' ); ?>
				<code class="rwp-ic">.github/workflows/post-release-to-wordpress.yml</code>
			</p>
			<p><?php esc_html_e( 'Paste in the following content:', 'releasewp' ); ?></p>
			<div class="rwp-code"><?php echo esc_html( $workflow_yaml ); ?></div>
			<div class="rwp-tip"><?php esc_html_e( 'This workflow fires automatically every time you publish a GitHub release. It builds a signed JSON request containing the release tag and notes, then sends it to WordPress.', 'releasewp' ); ?></div>
		</div>

		<div class="rwp-step">
			<h3><span class="rwp-num">5</span><?php esc_html_e( 'Publish a release and verify', 'releasewp' ); ?></h3>
			<p><?php esc_html_e( 'Go to your GitHub repository &#8594; Releases &#8594; Draft a new release. Set a tag (e.g. v1.0.0), write release notes in the description, and click Publish release.', 'releasewp' ); ?></p>
			<p><?php esc_html_e( 'Within a few seconds the Actions workflow will run and a new post should appear on your WordPress site.', 'releasewp' ); ?></p>
			<div class="rwp-tip"><?php esc_html_e( 'Not seeing the post? Check the Settings tab to confirm the correct post type is selected, then check your GitHub Actions run log for any errors.', 'releasewp' ); ?></div>
		</div>

	</div>
	<?php
}

// Register the REST endpoint — no user-capability gate; authentication is HMAC-only.
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_routes' );

/**
 * Register REST API routes for ReleaseWP.
 *
 * @return void
 */
function register_rest_routes(): void {
	register_rest_route(
		'releasewp/v1',
		'/post-update',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\handle_changelog_update',
			'permission_callback' => '__return_true', // Auth handled via HMAC inside the callback.
			'args'                => array(
				'title'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function ( $value ) {
						return is_string( $value ) && '' !== trim( $value );
					},
				),
				'content' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'wp_kses_post',
					'validate_callback' => function ( $value ) {
						return is_string( $value );
					},
				),
			),
		)
	);
}

/**
 * Verify a GitHub webhook HMAC-SHA256 signature.
 *
 * Reads the X-Hub-Signature-256 header and compares it against a locally
 * computed HMAC of the raw request body using the stored webhook secret.
 * Uses hash_equals() to prevent timing attacks.
 *
 * @param \WP_REST_Request $request The incoming REST request.
 * @return bool True when the signature is valid; false otherwise.
 */
function verify_webhook_signature( \WP_REST_Request $request ): bool {
	$secret = (string) get_option( 'releasewp_webhook_secret', '' );

	// If no secret is configured, reject all requests.
	if ( '' === $secret ) {
		return false;
	}

	$signature_header = $request->get_header( 'x-hub-signature-256' );
	if ( empty( $signature_header ) ) {
		return false;
	}

	$body     = $request->get_body();
	$expected = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

	return hash_equals( $expected, $signature_header );
}

/**
 * Handle the changelog update POST request.
 *
 * @param \WP_REST_Request $request The REST API request object.
 * @return \WP_REST_Response Response indicating success or failure.
 */
function handle_changelog_update( \WP_REST_Request $request ): \WP_REST_Response {
	// S-001: Verify GitHub webhook HMAC-SHA256 signature.
	if ( ! verify_webhook_signature( $request ) ) {
		return new \WP_REST_Response(
			array( 'message' => 'Forbidden: invalid or missing webhook signature.' ),
			403
		);
	}

	// A-001: Ensure the calling user (if any) can publish — belt-and-suspenders
	// guard kept even though HMAC is the primary gate for the GitHub Actions case.
	// Direct WordPress user calls (e.g. via Application Password) still need this.
	if ( is_user_logged_in() && ! current_user_can( 'publish_posts' ) ) {
		return new \WP_REST_Response(
			array( 'message' => 'Forbidden: insufficient capability.' ),
			403
		);
	}

	// Parameters are already sanitized via the 'args' declaration on the route.
	$version          = sanitize_text_field( (string) $request->get_param( 'title' ) );
	$markdown_content = (string) $request->get_param( 'content' );

	// S-002: Enable Parsedown safe mode to block raw HTML pass-through.
	$parsedown = new \Parsedown();
	$parsedown->setSafeMode( true );
	$parsedown->setMarkupEscaped( true );
	$content = wp_kses_post( $parsedown->text( $markdown_content ) );

	// S-004: get_option value is already validated through register_setting's
	// sanitize_callback (sanitize_post_type_option), so the stored value is
	// always a valid registered public post type.
	$post_type = sanitize_key( (string) get_option( 'releasewp_post_type', 'post' ) );

	// Apply title template.
	$title_template = sanitize_text_field( (string) get_option( 'releasewp_title_template', 'Version %version%' ) );
	$title          = str_replace( '%version%', $version, $title_template );

	$new_post = array(
		'post_title'   => wp_strip_all_tags( $title ),
		'post_content' => $content,
		'post_status'  => 'publish',
		'post_type'    => $post_type,
	);

	// C-001: Pass $wp_error = true so wp_insert_post() returns WP_Error on failure.
	$post_id = wp_insert_post( $new_post, true );

	if ( is_wp_error( $post_id ) ) {
		// C-003: Log the actual WP_Error message server-side.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional server-side logging, not debug code.
		error_log( '[ReleaseWP] wp_insert_post failed: ' . $post_id->get_error_message() );
		return new \WP_REST_Response(
			array( 'message' => 'Error creating post.' ),
			500
		);
	}

	return new \WP_REST_Response(
		array(
			'message' => 'Post created.',
			'post_id' => $post_id,
		),
		201
	);
}
