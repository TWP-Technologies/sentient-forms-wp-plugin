<?php

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'sentient_forms_wpdb' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'sf_wp_user' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: 'your_strong_user_password_here' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8' );

$table_prefix = 'wptests_';

define( 'WP_DEBUG', true );
// The packaged WordPress test fixture does not include development-only React
// refresh assets. Exercise the same minified core script path used in production.
define( 'SCRIPT_DEBUG', false );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'WP Tests' );
define( 'WP_PHP_BINARY', 'php' );

define( 'ABSPATH', dirname( __FILE__ ) . '/wordpress-tests-lib/src/' );
