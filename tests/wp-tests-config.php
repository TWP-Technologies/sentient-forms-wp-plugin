<?php

define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'wp' );
define( 'DB_PASSWORD', 'password' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8' );

$table_prefix = 'wptests_';

define( 'WP_DEBUG', true );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'WP Tests' );
define( 'WP_PHP_BINARY', 'php' );

define( 'ABSPATH', dirname( __FILE__ ) . '/wordpress-tests-lib/src/' );
