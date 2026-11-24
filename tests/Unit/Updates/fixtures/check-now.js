/**
 * Clicks the Settings page's Check Now button with a given AJAX response.
 *
 * Loads assets/js/admin-updates.js against a minimal DOM, answers its fetch
 * with the JSON body passed as argv[3], and prints what the version card shows.
 * Usage: node check-now.js <installed version> <response JSON> [<i18n JSON>].
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');

var currentVersion = process.argv[2];
var response = JSON.parse(process.argv[3]);
var i18n = process.argv[4] ? JSON.parse(process.argv[4]) : {};

function element() {
	var classes = new Set();
	return {
		innerHTML: '',
		disabled: false,
		listeners: {},
		classList: {
			add: function(name) { classes.add(name); },
			remove: function(name) { classes.delete(name); },
			contains: function(name) { return classes.has(name); }
		},
		addEventListener: function(type, fn) { this.listeners[type] = fn; }
	};
}

var elements = {
	'bricks-mcp-check-update-btn': element(),
	'bricks-mcp-check-update-spinner': element(),
	'bricks-mcp-version-text': element()
};
var versionCard = element();

var document = {
	readyState: 'complete',
	getElementById: function(id) { return elements[id] || null; },
	querySelector: function(selector) { return '.bricks-mcp-version-card' === selector ? versionCard : null; },
	createElement: function() {
		var text = '';
		return {
			appendChild: function(node) { text += node.text; },
			get innerHTML() {
				return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
			}
		};
	},
	createTextNode: function(text) { return { text: text }; },
	addEventListener: function() {}
};

var requests = [];
var context = vm.createContext({
	document: document,
	navigator: {},
	setTimeout: setTimeout,
	FormData: function() {
		var fields = {};
		this.append = function(key, value) { fields[key] = value; };
		requests.push(fields);
	},
	fetch: function() {
		return Promise.resolve({ json: function() { return Promise.resolve(response); } });
	},
	bricksMcpUpdates: {
		ajaxUrl: 'http://localhost/wp-admin/admin-ajax.php',
		nonce: 'nonce_bricks_mcp_settings_nonce',
		currentVersion: currentVersion,
		updateCoreUrl: 'http://localhost/wp-admin/update-core.php',
		i18n: i18n
	}
});

vm.runInContext(fs.readFileSync(path.join(__dirname, '../../../../assets/js/admin-updates.js'), 'utf8'), context);

var button = elements['bricks-mcp-check-update-btn'];
Promise.resolve(button.listeners.click()).then(function() {
	return new Promise(function(resolve) { setTimeout(resolve, 0); });
}).then(function() {
	process.stdout.write(JSON.stringify({
		action: requests.length ? requests[0].action : null,
		html: elements['bricks-mcp-version-text'].innerHTML,
		hasUpdateClass: versionCard.classList.contains('bricks-mcp-version-card--has-update'),
		buttonEnabled: !button.disabled
	}));
});
