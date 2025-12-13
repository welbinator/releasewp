<?php
/**
 * Plugin Name: ReleaseWP
 * Description: Handles posting changelog updates from GitHub to a custom post type in WordPress.
 * Version: 1.0
 * Author: James Welbes
 */

namespace ReleaseWP;

require_once plugin_dir_path( __FILE__ ) . 'Parsedown.php';

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

	// Create post array
	$new_post = array(
		'post_title'   => wp_strip_all_tags( $title ),
		'post_content' => $content,
		'post_status'  => 'publish',
		'post_type'    => 'update', // Replace 'update' with your custom post type slug.
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