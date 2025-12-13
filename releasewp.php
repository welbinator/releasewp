<?php
/**
 * Plugin Name: ReleaseWP
 * Description: Handles posting changelog updates from GitHub to a custom post type in WordPress.
 * Version: 1.0
 * Author: James Welbes
 */

namespace ReleaseWP;

require_once plugin_dir_path( __FILE__ ) . 'Parsedown.php';

// Register settings page
add_action( 'admin_menu', __NAMESPACE__ . '\\register_settings_page' );
add_action( 'admin_init', __NAMESPACE__ . '\\register_settings' );

function register_settings_page() {
	add_options_page(
		'ReleaseWP Settings',
		'ReleaseWP',
		'manage_options',
		'releasewp-settings',
		__NAMESPACE__ . '\\render_settings_page'
	);
}

function register_settings() {
	register_setting( 'releasewp_settings', 'releasewp_post_type' );

	add_settings_section(
		'releasewp_main_section',
		'Post Type Settings',
		null,
		'releasewp-settings'
	);

	add_settings_field(
		'releasewp_post_type',
		'Target Post Type',
		__NAMESPACE__ . '\\render_post_type_field',
		'releasewp-settings',
		'releasewp_main_section'
	);
}

function render_post_type_field() {
	$selected_post_type = get_option( 'releasewp_post_type', 'post' );
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
	echo '<p class="description">Select the post type where changelog updates will be published.</p>';
}

function render_settings_page() {
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

// Hook to handle POST request
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'releasewp/v1',
			'/post-update',
			array(
				'methods'              => 'POST',
				'callback'             => __NAMESPACE__ . '\\handle_changelog_update',
				'permissions_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}
);

// Function to handle the POST request
function handle_changelog_update( \WP_REST_Request $request ) {
	$title            = $request->get_param( 'title' );
	$markdown_content = $request->get_param( 'content' );

	// Convert Markdown to HTML
	$Parsedown = new \Parsedown();
	$content   = wp_kses_post( $Parsedown->text( $markdown_content ) );

	// Get the configured post type from settings
	$post_type = get_option( 'releasewp_post_type', 'post' );

	// Create post array
	$new_post = array(
		'post_title'   => wp_strip_all_tags( $title ),
		'post_content' => $content,
		'post_status'  => 'publish',
		'post_type'    => $post_type,
	);

	// Insert the post into the database and check for errors
	$post_id = wp_insert_post( $new_post );

	if ( is_wp_error( $post_id ) ) {
		// If there's an error, return a REST response indicating failure
		return new \WP_REST_Response( 'Error creating post', 500 );
	}

	// If successful, return a success message
	return new \WP_REST_Response( 'Post Created', 200 );
}