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
 * Render the settings page.
 *
 * @return void
 */
function render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'releasewp_settings' );
			do_settings_sections( 'releasewp-settings' );
			submit_button( 'Save Settings' );
			?>
		</form>
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
