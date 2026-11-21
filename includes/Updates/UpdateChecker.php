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
	 * Pattern a release asset URL must match: this repository's release downloads on github.com.
	 *
	 * @var string
	 */
	private const RELEASE_ASSET_URL_PATTERN = '#^https://github\.com/cristianuibar/bricks-mcp/releases/download/(?!\.\.?/)[^/?\#\s]+/(?!\.\.?\z)[^/?\#\s]+\z#';

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
		// Runs last, so a ZIP supplied by any other download filter is verified too.
		add_filter( 'upgrader_pre_download', [ $this, 'verify_download' ], PHP_INT_MAX, 4 );

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

		// Offer only a trusted package with a valid checksum, so WordPress never caches an update that installs unverified.
		if ( ! self::is_release_asset_url( $remote['package'] ?? null ) || '' === self::release_sha256( $remote ) ) {
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

		// Strip leading "v" from tag name (e.g. "v1.2.3" → "1.2.3").
		$version = is_array( $release ) && is_string( $release['tag_name'] ?? null ) ? ltrim( $release['tag_name'], 'v' ) : '';

		if ( '' === $version ) {
			set_transient( self::TRANSIENT_KEY, [], 5 * MINUTE_IN_SECONDS );
			return [];
		}

		// Find the first trusted plugin ZIP, then the checksum file published for that ZIP ("<zip name>.sha256").
		$package  = '';
		$zip_name = '';
		$sha256   = '';
		$assets   = [];
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( is_array( $asset ) && is_string( $asset['name'] ?? null ) ) {
					$assets[] = $asset;
				}
			}
		}
		foreach ( $assets as $asset ) {
			if ( str_ends_with( $asset['name'], '.zip' ) && self::is_release_asset_url( $asset['browser_download_url'] ?? null ) ) {
				$package  = $asset['browser_download_url'];
				$zip_name = $asset['name'];
				break;
			}
		}
		foreach ( $assets as $asset ) {
			if ( '' === $zip_name || $asset['name'] !== $zip_name . '.sha256' ) {
				continue;
			}
			$url = self::is_release_asset_url( $asset['browser_download_url'] ?? null ) ? $asset['browser_download_url'] : '';
			if ( '' !== $url ) {
				$checksum_response = wp_safe_remote_get(
					$url,
					[
						'timeout'    => 10,
						'User-Agent' => 'Bricks-MCP-UpdateChecker/1.0',
					]
				);
				// sha256sum format: "hexhash  filename" — accept only a leading 64-char hex hash.
				if ( ! is_wp_error( $checksum_response ) && 200 === wp_remote_retrieve_response_code( $checksum_response )
					&& preg_match( '/^([a-f0-9]{64})(?:\s|\z)/i', trim( wp_remote_retrieve_body( $checksum_response ) ), $matches ) ) {
					$sha256 = strtolower( $matches[1] );
				}
			}

			// A published checksum that cannot be fetched or parsed must not silently disable verification.
			if ( '' === $sha256 ) {
				set_transient( self::TRANSIENT_KEY, [], 5 * MINUTE_IN_SECONDS );
				return [];
			}
			break;
		}

		$data = [
			'version' => $version,
			'package' => $package,
			'url'     => is_string( $release['html_url'] ?? null ) ? $release['html_url'] : '',
			'sha256'  => $sha256,
		];

		set_transient( self::TRANSIENT_KEY, $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Verify the downloaded plugin ZIP against the expected SHA-256 hash.
	 *
	 * Hooked to `upgrader_pre_download`. Returning a file path short-circuits
	 * WordPress's own download and uses our already-verified temp file. A ZIP
	 * that an earlier filter supplied for this plugin is verified the same way.
	 * Returning WP_Error aborts the update with a clear error message, including
	 * when the release has no valid checksum. Returning false (the default $reply
	 * value) lets WordPress handle downloads for other plugins normally.
	 *
	 * The expected hash comes from the cached release data (refetched if the cache
	 * is empty), because WordPress often installs from its own cached update data
	 * without running check_update() in the same request.
	 *
	 * @param false|string|\WP_Error $reply      Current reply value (false = not handled yet, string = ZIP path from an earlier filter).
	 * @param string                 $package    URL of the package to download.
	 * @param \WP_Upgrader           $upgrader   WP_Upgrader instance.
	 * @param array<string,mixed>    $hook_extra Extra data about the update (includes 'plugin' key).
	 * @return false|string|\WP_Error
	 */
	public function verify_download( $reply, string $package, $upgrader, array $hook_extra ) {
		// Only verify our own plugin (same basename check_update() offers updates for) — leave other plugins untouched.
		$plugin = $hook_extra['plugin'] ?? '';
		if ( BRICKS_MCP_PLUGIN_BASENAME !== $plugin ) {
			return $reply;
		}

		// An earlier filter already aborted the download.
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		// Refuse a package that is not one of this repository's release downloads.
		if ( ! self::is_release_asset_url( $package ) ) {
			return new \WP_Error(
				'untrusted_package',
				__( 'Update refused: the package URL is not a Bricks MCP release download on GitHub.', 'bricks-mcp' )
			);
		}

		// Refuse the update when the release has no valid checksum.
		$remote   = $this->get_update_data();
		$expected = self::release_sha256( $remote );
		if ( '' === $expected ) {
			return new \WP_Error(
				'checksum_missing',
				__( 'Update integrity check failed: the release has no valid SHA-256 checksum file.', 'bricks-mcp' )
			);
		}

		// The checksum belongs to one release: refuse a package from an older offer WordPress still has cached.
		if ( ( $remote['package'] ?? '' ) !== $package ) {
			return new \WP_Error(
				'stale_package',
				__( 'Update refused: the update offer is out of date. Check for updates again.', 'bricks-mcp' )
			);
		}

		if ( false === $reply ) {
			// Download the ZIP ourselves so we can verify it before WordPress uses it.
			$temp_file = download_url( $package );

			if ( is_wp_error( $temp_file ) ) {
				return $temp_file;
			}
		} elseif ( is_string( $reply ) ) {
			// Verify the ZIP an earlier filter supplied; it is not ours to delete.
			$temp_file = $reply;
		} else {
			$temp_file = '';
		}

		$actual_hash = ( '' !== $temp_file && is_file( $temp_file ) ) ? hash_file( 'sha256', $temp_file ) : false;

		if ( ! is_string( $actual_hash ) || ! hash_equals( $expected, $actual_hash ) ) {
			if ( false === $reply ) {
				wp_delete_file( $temp_file );
			}
			return new \WP_Error(
				'checksum_mismatch',
				__( 'Update integrity check failed: SHA-256 checksum does not match expected value.', 'bricks-mcp' )
			);
		}

		// Hash matches — return the verified temp file path so WordPress skips its redundant download.
		return $temp_file;
	}

	/**
	 * The release's SHA-256 checksum, lowercased, or '' when it has no valid one.
	 *
	 * @param array<string,mixed> $remote Release data from get_update_data().
	 * @return string
	 */
	private static function release_sha256( array $remote ): string {
		$sha256 = is_string( $remote['sha256'] ?? null ) ? strtolower( $remote['sha256'] ) : '';

		return 1 === preg_match( '/^[0-9a-f]{64}\z/', $sha256 ) ? $sha256 : '';
	}

	/**
	 * Whether a URL is a release asset download of this repository on github.com over https.
	 *
	 * @param mixed $url Candidate URL from the release JSON or the update transient.
	 * @return bool
	 */
	private static function is_release_asset_url( $url ): bool {
		return is_string( $url ) && 1 === preg_match( self::RELEASE_ASSET_URL_PATTERN, $url );
	}

	/**
	 * Remove this plugin's offer from WordPress's cached plugin updates.
	 *
	 * Runs on plugin deactivation: an inactive plugin loads no verifier, so a
	 * cached offer must not stay installable.
	 *
	 * @return void
	 */
	public static function forget_update_offer(): void {
		$updates = get_site_transient( 'update_plugins' );

		if ( is_object( $updates ) && isset( $updates->response[ BRICKS_MCP_PLUGIN_BASENAME ] ) ) {
			unset( $updates->response[ BRICKS_MCP_PLUGIN_BASENAME ] );
			set_site_transient( 'update_plugins', $updates );
		}
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
