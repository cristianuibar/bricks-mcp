/**
 * Bricks MCP Extensions admin settings JS.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

(function() {
	'use strict';

	var iconMap = {
		pass: 'dashicons-yes-alt',
		warn: 'dashicons-warning',
		fail: 'dashicons-dismiss',
		skipped: 'dashicons-minus'
	};

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(String(str)));
		return div.innerHTML;
	}

	function escapeAttr(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	}

	function initDiagnostics() {
		var runBtn = document.getElementById('bricks-mcp-run-diagnostics');
		var copyBtn = document.getElementById('bricks-mcp-copy-results');
		var spinner = document.getElementById('bricks-mcp-diagnostics-spinner');
		var results = document.getElementById('bricks-mcp-diagnostics-results');
		var diagnosticsData = null;
		var i18n = bricksMcpUpdates.i18n || {};

		if (!runBtn || !results) {
			return;
		}

		runBtn.addEventListener('click', function() {
			runBtn.disabled = true;
			if (spinner) {
				spinner.classList.add('is-active');
			}
			results.innerHTML = '';
			if (copyBtn) {
				copyBtn.hidden = true;
			}

			var formData = new FormData();
			formData.append('action', 'bricks_mcp_run_diagnostics');
			formData.append('nonce', bricksMcpUpdates.nonce);

			fetch(bricksMcpUpdates.ajaxUrl, {
				method: 'POST',
				body: formData
			})
			.then(function(response) {
				return response.json();
			})
			.then(function(response) {
				if (!response.success) {
					var msg = response.data && response.data.message ? response.data.message : (i18n.error || 'An error occurred.');
					results.innerHTML = '<p class="bricks-mcp-error">' + escapeHtml(msg) + '</p>';
					return;
				}

				diagnosticsData = response.data;
				var html = '<p class="bricks-mcp-diagnostics-summary"><strong>' + escapeHtml(response.data.summary) + '</strong></p>';

				response.data.checks.forEach(function(check) {
					var icon = iconMap[check.status] || 'dashicons-minus';
					var fixHtml = '';
					if (check.fix_steps && check.fix_steps.length > 0) {
						fixHtml = '<div class="bricks-mcp-check-fixes"><strong>' + escapeHtml(i18n.howToFix || 'How to fix:') + '</strong><ul>';
						check.fix_steps.forEach(function(step) {
							fixHtml += '<li>' + escapeHtml(step) + '</li>';
						});
						fixHtml += '</ul></div>';
					}
					html += '<div class="bricks-mcp-check bricks-mcp-check--' + escapeAttr(check.status) + '">';
					html += '<span class="dashicons ' + escapeAttr(icon) + '"></span>';
					html += '<div class="bricks-mcp-check-content">';
					html += '<strong>' + escapeHtml(check.label) + '</strong>';
					html += '<p>' + escapeHtml(check.message) + '</p>';
					html += fixHtml;
					html += '</div></div>';
				});

				results.innerHTML = html;
				if (copyBtn) {
					copyBtn.hidden = false;
				}
			})
			.catch(function() {
				results.innerHTML = '<p class="bricks-mcp-error">' + escapeHtml(i18n.requestFail || 'Request failed. Please try again.') + '</p>';
			})
			.finally(function() {
				runBtn.disabled = false;
				if (spinner) {
					spinner.classList.remove('is-active');
				}
			});
		});

		if (copyBtn) {
			copyBtn.addEventListener('click', function() {
				if (!diagnosticsData) {
					return;
				}
				var text = '';
				diagnosticsData.checks.forEach(function(check) {
					text += '[' + check.status.toUpperCase() + '] ' + check.label + ': ' + check.message + '\n';
					if (check.fix_steps && check.fix_steps.length > 0) {
						check.fix_steps.forEach(function(step) {
							text += '  Fix: ' + step + '\n';
						});
					}
				});
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function() {
						copyBtn.textContent = i18n.copied || 'Copied!';
						setTimeout(function() {
							copyBtn.textContent = i18n.copyResults || 'Copy Results';
						}, 2000);
					});
				}
			});
		}
	}

	function initCheckNow() {
		var btn = document.getElementById('bricks-mcp-check-update-btn');
		var spinner = document.getElementById('bricks-mcp-check-update-spinner');
		var versionText = document.getElementById('bricks-mcp-version-text');
		var versionCard = document.querySelector('.bricks-mcp-version-card');

		if (!btn) {
			return;
		}

		btn.addEventListener('click', function() {
			btn.disabled = true;
			if (spinner) {
				spinner.classList.add('is-active');
			}

			var formData = new FormData();
			formData.append('action', 'bricks_mcp_check_update');
			formData.append('nonce', bricksMcpUpdates.nonce);

			fetch(bricksMcpUpdates.ajaxUrl, {
				method: 'POST',
				body: formData
			})
			.then(function(response) {
				return response.json();
			})
			.then(function(data) {
				if (data.success && data.data && versionText) {
					var remoteVersion = data.data.version || '';
					var currentVersion = bricksMcpUpdates.currentVersion;
					var html = '<strong>v' + escapeHtml(currentVersion) + '</strong>';

					if (remoteVersion && data.data.update_available === true) {
						html += ' &mdash; <span class="bricks-mcp-update-available">v' +
							escapeHtml(remoteVersion) + ' available</span> ' +
							'<a href="' + escapeAttr(bricksMcpUpdates.updateCoreUrl) + '">Update</a>';
						if (versionCard) {
							versionCard.classList.add('bricks-mcp-version-card--has-update');
						}
					} else if (data.data.blocked_version) {
						// A newer release whose checksum could not be verified is not "up to date".
						var blocked = (bricksMcpUpdates.i18n && bricksMcpUpdates.i18n.updateBlocked) || 'v%s found, but its checksum could not be verified. Check again later.';
						html += ' &mdash; <span class="bricks-mcp-update-blocked">' +
							escapeHtml(blocked.replace('%s', data.data.blocked_version)) + '</span>';
						if (versionCard) {
							versionCard.classList.remove('bricks-mcp-version-card--has-update');
						}
					} else {
						html += ' &mdash; <span class="bricks-mcp-up-to-date">up to date</span>';
						if (versionCard) {
							versionCard.classList.remove('bricks-mcp-version-card--has-update');
						}
					}

					versionText.innerHTML = html;
				}
			})
			.catch(function() {
			})
			.finally(function() {
				btn.disabled = false;
				if (spinner) {
					spinner.classList.remove('is-active');
				}
			});
		});
	}

	function init() {
		initDiagnostics();
		initCheckNow();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
