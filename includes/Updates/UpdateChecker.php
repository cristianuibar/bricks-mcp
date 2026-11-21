<?php
/**
 * Plugin update checker.
 *
 * Hooks into WordPress 5.8+ Update URI system to check GitHub Releases
 * for new plugin versions.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Updates;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UpdateChecker class.
 *
 * Manages plugin update checking via the modern `update_plugins_{$hostname}` filter,
 * with transient-based caching.
 */
final class UpdateChecker {

	/**
	 * GitHub Releases API URL for the latest release.
	 *
	 * @var string
	 */
	private const GITHUB_API_URL = 'https://api.github.com/repos/cristianuibar/bricks-mcp/releases/latest';

	/**
	 * Transient key for cached update data.
	 *
	 * @var string
	 */
	private const TRANSIENT_KEY = 'bricks_mcp_update_data';

	/**
	 * Cache TTL in seconds (12 hours).
	 *
	 * @var int
	 */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Initialize update checker hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// Modern WP 5.8+ update hook (hostname extracted from Update URI header).
		add_filter( 'update_plugins_github.com', [ $this, 'check_update' ], 10, 4 );

		// Intercept the plugin download to verify SHA-256 checksum before WP installs.
		add_filter( 'upgrader_pre_download', [ $this, 'verify_download' ], 10, 4 );

		// AJAX handler for "Check Now" button on settings page.
		add_action( 'wp_ajax_bricks_mcp_check_update', [ $this, 'ajax_check_update' ] );

		// Refresh update data on plugins page when our cache has expired.
		add_action( 'load-plugins.php', [ $this, 'maybe_refresh_on_plugins_page' ] );
	}

	/**
	 * Refresh update data when visiting the plugins page if our cache expired.
	 *
	 * WordPress only runs wp_update_plugins() on cron (every 12 hours).
	 * This ensures the plugins page always reflects the latest release by
	 * forcing a re-check when our local cache has expired.
	 *
	 * @return void
	 */
	public function maybe_refresh_on_plugins_page(): void {
		if ( false !== get_transient( self::TRANSIENT_KEY ) ) {
			return;
		}

		// Our cache expired — clear WordPress update cache and force re-check.
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
	}

	/**
	 * Check for plugin updates via the update_plugins_{$hostname} filter.
	 *
	 * @param mixed                $update      The update data. Default false.
	 * @param array<string,string> $plugin_data Plugin header data.
	 * @param string               $plugin_file Plugin file relative to plugins directory.
	 * @param array<string>        $locales     Installed locales.
	 * @return mixed Update data array if update available, original value otherwise.
	 */
	public function check_update( $update, array $plugin_data, string $plugin_file, array $locales ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature fixed by the update_plugins_{$hostname} filter.
		if ( BRICKS_MCP_PLUGIN_BASENAME !== $plugin_file ) {
			return $update;
		}

		$remote = $this->get_update_data();

		if ( empty( $remote['version'] ) ) {
			return $update;
		}

		// Only return update data if remote version is newer.
		if ( version_compare( $plugin_data['Version'], $remote['version'], '>=' ) ) {
			return $update;
		}

		return [
			'id'           => $plugin_data['UpdateURI'],
			'slug'         => 'bricks-mcp',
			'version'      => $remote['version'],
			'url'          => $remote['url'] ?? '',
			'package'      => $remote['package'] ?? '',
			'tested'       => $remote['tested'] ?? '',
			'requires_php' => $remote['requires_php'] ?? '8.2',
		];
	}

	/**
	 * Get update data from cache or GitHub Releases API.
	 *
	 * Fetches the latest release from GitHub, extracts the version from
	 * `tag_name` (stripping a leading "v"), the download URL of the first
	 * `.zip` asset, and the SHA-256 from the `<zip name>.sha256` asset.
	 *
	 * @return array<string,mixed> Update data array.
	 */
	private function get_update_data(): array {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::GITHUB_API_URL,
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Bricks-MCP-UpdateChecker/1.0',
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cache empty result briefly to avoid hammering on failure.
			set_transient( self::TRANSIENT_KEY, [], 5 * MINUTE_IN_SECONDS );
			return [];
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			set_transient( self::TRANSIENT_KEY, [], 5 * MINUTE_IN_SECONDS );
			return [];
		}

		// Strip leading "v" from tag name (e.g. "v1.2.3" → "1.2.3").
		$version = ltrim( $release['tag_name'], 'v' );

		// Find the plugin ZIP, then the checksum file published for that ZIP ("<zip name>.sha256").
		$package  = '';
		$zip_name = '';
		$sha256   = '';
		$assets   = ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) ? $release['assets'] : [];
		foreach ( $assets as $asset ) {
			$name = $asset['name'] ?? '';
			if ( str_ends_with( $name, '.zip' ) ) {
				$package  = $asset['browser_download_url'] ?? '';
				$zip_name = $name;
				break;
			}
		}
		foreach ( $assets as $asset ) {
			if ( '' === $zip_name || ( $asset['name'] ?? '' ) !== $zip_name . '.sha256' ) {
				continue;
			}
			$checksum_url = $asset['browser_download_url'] ?? '';
			if ( ! empty( $checksum_url ) ) {
				$checksum_response = wp_remote_get(
					$checksum_url,
					[
						'timeout'    => 10,
						'User-Agent' => 'Bricks-MCP-UpdateChecker/1.0',
					]
				);
				if ( ! is_wp_error( $checksum_response ) && 200 === wp_remote_retrieve_response_code( $checksum_response ) ) {
					// sha256sum format: "hexhash  filename" — extract only the 64-char hex hash.
					$raw    = trim( wp_remote_retrieve_body( $checksum_response ) );
					$sha256 = substr( $raw, 0, 64 );
				}
			}
			break;
		}

		$data = [
			'version' => $version,
			'package' => $package,
			'url'     => $release['html_url'] ?? '',
			'sha256'  => $sha256,
		];

		set_transient( self::TRANSIENT_KEY, $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Verify the downloaded plugin ZIP against the expected SHA-256 hash.
	 *
	 * Hooked to `upgrader_pre_download`. Returning a file path short-circuits
	 * WordPress's own download and uses our already-verified temp file.
	 * Returning WP_Error aborts the update with a clear error message, including
	 * when the release has no valid checksum. Returning false (the default $reply
	 * value) lets WordPress handle downloads for other plugins normally.
	 *
	 * The expected hash comes from the cached release data (refetched if the cache
	 * is empty), because WordPress often installs from its own cached update data
	 * without running check_update() in the same request.
	 *
	 * @param false|string|\WP_Error $reply      Current reply value (false = not handled yet).
	 * @param string                 $package    URL of the package to download.
	 * @param \WP_Upgrader           $upgrader   WP_Upgrader instance.
	 * @param array<string,mixed>    $hook_extra Extra data about the update (includes 'plugin' key).
	 * @return false|string|\WP_Error
	 */
	public function verify_download( $reply, string $package, $upgrader, array $hook_extra ) {
		// If an earlier filter already handled the download, pass through.
		if ( false !== $reply ) {
			return $reply;
		}

		// Only verify our own plugin (same basename check_update() offers updates for) — leave other plugins untouched.
		$plugin = $hook_extra['plugin'] ?? '';
		if ( BRICKS_MCP_PLUGIN_BASENAME !== $plugin ) {
			return $reply;
		}

		// Refuse the update when the release has no valid checksum.
		$remote   = $this->get_update_data();
		$expected = is_string( $remote['sha256'] ?? null ) ? strtolower( $remote['sha256'] ) : '';
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $expected ) ) {
			return new \WP_Error(
				'checksum_missing',
				__( 'Update integrity check failed: the release has no valid SHA-256 checksum file.', 'bricks-mcp' )
			);
		}

		// Download the ZIP ourselves so we can verify it before WordPress uses it.
		$temp_file = download_url( $package );

		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$actual_hash = hash_file( 'sha256', $temp_file );

		if ( $actual_hash !== $expected ) {
			wp_delete_file( $temp_file );
			return new \WP_Error(
				'checksum_mismatch',
				__( 'Update integrity check failed: SHA-256 checksum does not match expected value.', 'bricks-mcp' )
			);
		}

		// Hash matches — return the verified temp file path so WordPress skips its redundant download.
		return $temp_file;
	}

	/**
	 * Clear the cached update data.
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Get cached update data without triggering a remote fetch.
	 *
	 * Used by the Settings page version card to display update status.
	 *
	 * @return array<string,mixed> Cached update data, or empty array if no cache.
	 */
	public function get_cached_update_data(): array {
		$cached = get_transient( self::TRANSIENT_KEY );

		return false !== $cached ? $cached : [];
	}

	/**
	 * AJAX handler for "Check Now" button.
	 *
	 * Clears all caches and forces a fresh update check.
	 *
	 * @return void
	 */
	public function ajax_check_update(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		// Clear our own cache.
		$this->clear_cache();

		// Clear WordPress core update cache.
		delete_site_transient( 'update_plugins' );

		// Force WordPress to re-check all plugin updates.
		wp_update_plugins();

		// Fetch fresh data.
		$data = $this->get_update_data();

		wp_send_json_success( $data );
	}
}
