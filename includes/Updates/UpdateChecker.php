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
	 * GitHub Releases API URL for the releases list.
	 *
	 * @var string
	 */
	private const GITHUB_API_URL = 'https://api.github.com/repos/cristianuibar/bricks-mcp/releases?per_page=30';

	/**
	 * Plugin file of the MCP Adapter, required by 2.x releases.
	 *
	 * @var string
	 */
	private const ADAPTER_PLUGIN = 'mcp-adapter/mcp-adapter.php';

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
	 * Expected SHA-256 hash for the update ZIP.
	 *
	 * Populated by check_update() from cached release data.
	 * Used by verify_download() to verify the downloaded file before WordPress installs it.
	 *
	 * @var string
	 */
	private string $expected_sha256 = '';

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

		// Never auto-install a major version jump.
		add_filter( 'auto_update_plugin', [ $this, 'block_major_auto_update' ], PHP_INT_MAX, 2 );

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
	public function check_update( $update, array $plugin_data, string $plugin_file, array $locales ) {
		$remote = $this->get_update_data();

		if ( empty( $remote['version'] ) || ! $this->is_installable( $remote['version'] ) ) {
			return $update;
		}

		// Cache expected hash so verify_download() can use it when WP downloads the ZIP.
		$this->expected_sha256 = $remote['sha256'] ?? '';

		// Only return update data if remote version is newer.
		if ( version_compare( $plugin_data['Version'], $remote['version'], '>=' ) ) {
			return $update;
		}

		$offer = [
			'id'           => $plugin_data['UpdateURI'],
			'slug'         => 'bricks-mcp',
			'version'      => $remote['version'],
			'url'          => $remote['url'] ?? '',
			'package'      => $remote['package'] ?? '',
			'tested'       => $remote['tested'] ?? '',
			'requires_php' => $remote['requires_php'] ?? '8.2',
		];

		if ( version_compare( $remote['version'], '2', '>=' ) ) {
			$offer['requires'] = '7.1';
		}

		return $offer;
	}

	/**
	 * Refuse automatic installation of a 2.x or higher release over an installed 1.x.
	 *
	 * @param bool|null $update Whether to auto-update.
	 * @param object    $item   Update offer.
	 * @return bool|null
	 */
	public function block_major_auto_update( $update, $item ) {
		if ( BRICKS_MCP_PLUGIN_BASENAME !== ( $item->plugin ?? '' ) ) {
			return $update;
		}

		if ( (int) BRICKS_MCP_VERSION < 2 && (int) ( $item->new_version ?? 0 ) >= 2 ) {
			return false;
		}

		return $update;
	}

	/**
	 * Whether this site may install the given release version.
	 *
	 * 2.x and higher need WordPress 7.1+, Bricks 2.5+ (betas accepted) and the MCP Adapter plugin.
	 *
	 * @param string $version Release version.
	 * @return bool
	 */
	private function is_installable( string $version ): bool {
		return [] === $this->unmet_requirements( $version );
	}

	/**
	 * Requirements the site does not meet for the given release version.
	 *
	 * On multisite the adapter must be network-activated, because plugin files are shared network-wide.
	 *
	 * @param string $version Release version.
	 * @return array<int,string> Unmet requirements, empty when installable.
	 */
	private function unmet_requirements( string $version ): array {
		if ( version_compare( $version, '2', '<' ) ) {
			return [];
		}

		global $wp_version;

		$unmet = [];

		if ( version_compare( (string) $wp_version, '7.1', '<' ) ) {
			$unmet[] = 'WordPress 7.1 or higher';
		}

		if ( ! defined( 'BRICKS_VERSION' ) || version_compare( (string) BRICKS_VERSION, '2.5-dev', '<' ) ) {
			$unmet[] = 'Bricks Builder 2.5 or higher';
		}

		$adapter_active = is_multisite() ? is_plugin_active_for_network( self::ADAPTER_PLUGIN ) : is_plugin_active( self::ADAPTER_PLUGIN );

		if ( ! $adapter_active ) {
			$unmet[] = 'an active MCP Adapter plugin';
		}

		return $unmet;
	}

	/**
	 * Get update data from cache or GitHub Releases API.
	 *
	 * Fetches the releases list from GitHub, skips drafts and prereleases, and picks
	 * the highest version this site may install. Extracts the version from
	 * `tag_name` (stripping a leading "v") and the ZIP download URL from the assets.
	 *
	 * @return array<string,mixed> Update data array.
	 */
	private function get_update_data(): array {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( false !== $cached && $this->is_installable( (string) ( $cached['version'] ?? '0' ) ) ) {
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

		$releases = json_decode( wp_remote_retrieve_body( $response ), true );
		$release  = null;
		$version  = '';

		if ( is_array( $releases ) ) {
			foreach ( $releases as $candidate ) {
				if ( ! is_array( $candidate ) || ! empty( $candidate['draft'] ) || ! empty( $candidate['prerelease'] ) || ! is_string( $candidate['tag_name'] ?? null ) ) {
					continue;
				}

				// Strip leading "v" from tag name (e.g. "v1.2.3" → "1.2.3").
				$candidate_version = ltrim( $candidate['tag_name'], 'v' );

				if ( 1 === preg_match( '/^\d+\.\d+\.\d+$/', $candidate_version )
					&& $this->is_installable( $candidate_version )
					&& ( null === $release || version_compare( $candidate_version, $version, '>' ) ) ) {
					$release = $candidate;
					$version = $candidate_version;
				}
			}
		}

		if ( null === $release ) {
			set_transient( self::TRANSIENT_KEY, [], 5 * MINUTE_IN_SECONDS );
			return [];
		}

		// Scan assets for the plugin ZIP and a matching .sha256 checksum file.
		$package = '';
		$sha256  = '';
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				$name = $asset['name'] ?? '';
				if ( str_ends_with( $name, '.zip' ) && empty( $package ) ) {
					$package = $asset['browser_download_url'] ?? '';
				} elseif ( str_ends_with( $name, '.sha256' ) ) {
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
				}
			}
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
	 * Returning WP_Error aborts the update with a clear error message.
	 * Returning false (the default $reply value) lets WordPress handle the download normally.
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

		// Only verify our own plugin — leave other plugins untouched.
		$plugin = $hook_extra['plugin'] ?? '';
		if ( BRICKS_MCP_PLUGIN_BASENAME !== $plugin ) {
			return $reply;
		}

		if ( 1 === preg_match( '#/releases/download/v?(\d+\.\d+\.\d+)/#', $package, $target ) && (int) $target[1] > (int) BRICKS_MCP_VERSION ) {
			$unmet = $this->unmet_requirements( $target[1] );

			if ( [] !== $unmet ) {
				$this->clear_cache();
				delete_site_transient( 'update_plugins' );

				return new \WP_Error(
					'requirements_not_met',
					sprintf(
						/* translators: 1: Release version, 2: Unmet requirements */
						__( 'Bricks MCP %1$s cannot be installed. It requires: %2$s.', 'bricks-mcp' ),
						$target[1],
						implode( ', ', $unmet )
					)
				);
			}
		}

		// If no expected hash is cached, degrade gracefully — allow the update to proceed.
		if ( empty( $this->expected_sha256 ) ) {
			return $reply;
		}

		// Download the ZIP ourselves so we can verify it before WordPress uses it.
		$temp_file = download_url( $package );

		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$actual_hash = hash_file( 'sha256', $temp_file );

		if ( $actual_hash !== $this->expected_sha256 ) {
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

		return false !== $cached && $this->is_installable( (string) ( $cached['version'] ?? '0' ) ) ? $cached : [];
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
