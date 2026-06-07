<?php
/**
 * GitHub Releases auto-updater for ReleaseWP.
 *
 * Hooks into the WordPress plugin update system to check for new releases on
 * GitHub, display update notices in wp-admin, and verify SHA-256 integrity
 * before allowing installation.
 *
 * @package ReleaseWP
 */

namespace ReleaseWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub Releases updater.
 *
 * Checks for new releases from GitHub and integrates with the WordPress
 * plugin update mechanism. Verifies SHA-256 checksums before installation.
 */
class GitHub_Updater {

	/**
	 * GitHub repository owner.
	 *
	 * @var string
	 */
	private const GITHUB_USER = 'welbinator';

	/**
	 * GitHub repository name.
	 *
	 * @var string
	 */
	private const GITHUB_REPO = 'releasewp';

	/**
	 * WordPress plugin slug (folder/main-file.php).
	 *
	 * @var string
	 */
	private const PLUGIN_SLUG = 'releasewp/releasewp.php';

	/**
	 * Transient key for caching release data.
	 *
	 * @var string
	 */
	private const TRANSIENT = 'releasewp_github_release';

	/**
	 * Cache lifetime in seconds (12 hours).
	 *
	 * @var int
	 */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Wire up the WordPress filter hooks.
	 */
	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_pre_install', array( $this, 'verify_integrity' ), 10, 2 );
	}

	/**
	 * Inject an update payload into the update_plugins transient when a newer
	 * version is available on GitHub.
	 *
	 * @param object $transient The current update_plugins transient value.
	 * @return object The (possibly modified) transient.
	 */
	public function check_for_update( object $transient ): object {
		if ( empty( $transient->checked ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging.
			error_log( '[ReleaseWP Updater] check_for_update: transient->checked is empty, skipping.' );
			return $transient;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging.
			error_log( '[ReleaseWP Updater] check_for_update: get_release() returned null.' );
			return $transient;
		}

		$remote_version = ltrim( $release['tag_name'] ?? '', 'v' );
		$local_version  = $transient->checked[ self::PLUGIN_SLUG ] ?? '0.0.0';

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging.
		error_log(
			sprintf(
				'[ReleaseWP Updater] check_for_update: local=%s remote=%s needs_update=%s',
				$local_version,
				$remote_version,
				version_compare( $remote_version, $local_version, '>' ) ? 'yes' : 'no'
			)
		);

		if ( ! version_compare( $remote_version, $local_version, '>' ) ) {
			return $transient;
		}

		$zip_url = $this->find_asset_url( $release, '.zip' );
		if ( ! $zip_url ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging.
			error_log( '[ReleaseWP Updater] check_for_update: no .zip asset found in release.' );
			return $transient;
		}

		$transient->response[ self::PLUGIN_SLUG ] = (object) array(
			'slug'        => self::GITHUB_REPO,
			'plugin'      => self::PLUGIN_SLUG,
			'new_version' => $remote_version,
			'url'         => 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO,
			'package'     => $zip_url,
		);

		return $transient;
	}

	/**
	 * Populate the "View details" popup for this plugin in wp-admin.
	 *
	 * @param false|object|array<string, mixed> $result  Default false (or previously filtered).
	 * @param string                            $action  The API action being requested.
	 * @param object                            $args    Request arguments including slug.
	 * @return false|object The plugin info object or false to let WP handle it.
	 */
	public function plugin_info( $result, string $action, object $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ( $args->slug ?? '' ) !== self::GITHUB_REPO ) {
			return $result;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			return $result;
		}

		$remote_version = ltrim( $release['tag_name'] ?? '', 'v' );
		$zip_url        = $this->find_asset_url( $release, '.zip' );

		return (object) array(
			'name'          => 'ReleaseWP',
			'slug'          => self::GITHUB_REPO,
			'version'       => $remote_version,
			'author'        => 'James Welbes',
			'homepage'      => 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO,
			'download_link' => $zip_url,
			'sections'      => array(
				'description' => 'Handles posting changelog updates from GitHub to a custom post type in WordPress.',
				'changelog'   => nl2br( esc_html( $release['body'] ?? '' ) ),
			),
		);
	}

	/**
	 * Verify the SHA-256 checksum of the downloaded plugin zip before installation.
	 *
	 * Returns a WP_Error to abort the install if no checksum asset is found or
	 * the hash does not match. Fail-closed: missing checksum = blocked install.
	 *
	 * @param bool|\WP_Error       $response   Current upgrader response.
	 * @param array<string, mixed> $hook_extra Extra data including the plugin slug.
	 * @return bool|\WP_Error True to continue, WP_Error to abort.
	 */
	public function verify_integrity( $response, array $hook_extra ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$plugin = $hook_extra['plugin'] ?? '';
		if ( self::PLUGIN_SLUG !== $plugin ) {
			return $response;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			return new \WP_Error( 'releasewp_checksum_unavailable', 'ReleaseWP: unable to fetch release data for integrity check.' );
		}

		$checksum_url = $this->find_asset_url( $release, '.zip.sha256' );
		if ( ! $checksum_url ) {
			return new \WP_Error( 'releasewp_no_checksum', 'ReleaseWP: no SHA-256 checksum asset found — install blocked.' );
		}

		$zip_url = $this->find_asset_url( $release, '.zip' );
		if ( ! $zip_url ) {
			return new \WP_Error( 'releasewp_no_zip', 'ReleaseWP: no zip asset found — install blocked.' );
		}

		$checksum_response = wp_remote_get( $checksum_url );
		if ( is_wp_error( $checksum_response ) ) {
			return new \WP_Error( 'releasewp_checksum_fetch_failed', 'ReleaseWP: could not fetch SHA-256 checksum.' );
		}

		$checksum_body = trim( wp_remote_retrieve_body( $checksum_response ) );
		// sha256sum format: "<hash>  <filename>" — grab just the hash.
		$expected_hash = preg_split( '/\s+/', $checksum_body )[0] ?? '';

		if ( empty( $expected_hash ) || 64 !== strlen( $expected_hash ) ) {
			return new \WP_Error( 'releasewp_checksum_malformed', 'ReleaseWP: SHA-256 checksum asset is malformed — install blocked.' );
		}

		$zip_response = wp_remote_get( $zip_url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $zip_response ) ) {
			return new \WP_Error( 'releasewp_zip_fetch_failed', 'ReleaseWP: could not fetch zip for integrity check.' );
		}

		$actual_hash = hash( 'sha256', wp_remote_retrieve_body( $zip_response ) );

		if ( ! hash_equals( $expected_hash, $actual_hash ) ) {
			return new \WP_Error( 'releasewp_checksum_mismatch', 'ReleaseWP: SHA-256 checksum mismatch — install blocked.' );
		}

		return $response;
	}

	/**
	 * Fetch and cache the latest GitHub release JSON.
	 *
	 * @return array<string, mixed>|null Decoded release array, or null on failure.
	 */
	private function get_release(): ?array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$api_url  = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			self::GITHUB_USER,
			self::GITHUB_REPO
		);
		$response = wp_remote_get(
			$api_url,
			array(
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging.
			error_log( '[ReleaseWP Updater] get_release: wp_remote_get failed — ' . $response->get_error_message() );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging.
			error_log( sprintf( '[ReleaseWP Updater] get_release: GitHub API returned HTTP %d. Body: %s', $code, wp_remote_retrieve_body( $response ) ) );
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging.
			error_log( '[ReleaseWP Updater] get_release: failed to decode JSON from GitHub API.' );
			return null;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging.
		error_log( sprintf( '[ReleaseWP Updater] get_release: fetched release %s, caching for 12h.', $release['tag_name'] ?? '?' ) );

		set_transient( self::TRANSIENT, $release, self::CACHE_TTL );
		return $release;
	}

	/**
	 * Find the browser_download_url of a release asset whose name ends with the
	 * given suffix (e.g. '.zip' or '.zip.sha256').
	 *
	 * @param array<string, mixed> $release Release JSON array from the GitHub API.
	 * @param string               $suffix  Filename suffix to match.
	 * @return string|null Download URL or null if not found.
	 */
	private function find_asset_url( array $release, string $suffix ): ?string {
		foreach ( $release['assets'] ?? array() as $asset ) {
			if ( str_ends_with( $asset['name'] ?? '', $suffix ) ) {
				return $asset['browser_download_url'] ?? null;
			}
		}
		return null;
	}
}
