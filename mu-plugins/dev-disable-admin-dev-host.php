<?php
/**
 * Force admin SPA to use built assets instead of probing Vite dev server.
 *
 * Loaded as an MU plugin inside the WordPress container for E2E runs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( getenv( 'SENTIENT_E2E_DISABLE_ADMIN_DEV_HOST' ) ) {
	return;
}

add_filter(
	'sentient_forms_admin_asset_base_url',
	static function () {
		if ( defined( 'SENTIENT_FORMS_PLUGIN_URL' ) ) {
			return trailingslashit( SENTIENT_FORMS_PLUGIN_URL ) . 'assets/dist/';
		}

		return null;
	},
	1
);

// Disable dev host detection entirely for test runs.
add_filter(
	'sentient_forms_admin_dev_host',
	static function () {
		return null;
	},
	1
);
