<?php
require dirname(__DIR__) . '/vendor/autoload.php';

$tests_dir    = __DIR__ . '/wordpress-tests-lib';
$includes_dir = $tests_dir . '/tests/phpunit/includes';
$wp_dir       = __DIR__ . '/wordpress';

$ensure_directory = static function ( string $path ): bool {
    if ( is_dir( $path ) ) {
        return true;
    }

    return mkdir( $path, 0775, true );
};

if ( ! file_exists( "$includes_dir/functions.php" ) ) {
    // Download the WordPress test library using PHP's native functions.
    if ( ! $ensure_directory( $tests_dir ) ) {
        fwrite( STDERR, "Failed to create test directory: {$tests_dir}\n" );
        return;
    }

    $archive      = __DIR__ . '/wp.tar.gz';
    $download_url = 'https://codeload.github.com/WordPress/wordpress-develop/tar.gz/refs/tags/6.5.4';

    if ( ! file_exists( $archive ) ) {
        $data = file_get_contents( $download_url );
        if ( false === $data ) {
            fwrite( STDERR, "Failed to download WordPress test library.\n" );
            return;
        }
        file_put_contents( $archive, $data );
    }

    if ( file_exists( $archive ) ) {
        $tmp_dir = __DIR__ . '/wp-temp';
        if ( ! $ensure_directory( $tmp_dir ) ) {
            fwrite( STDERR, "Failed to create {$tmp_dir}\n" );
            return;
        }
        try {
            $phar = new PharData( $archive );
            $phar->extractTo( $tmp_dir, null, true );
            $extracted = glob( $tmp_dir . '/wordpress-develop-*' );
            if ( ! empty( $extracted ) ) {
                rename( $extracted[0], $tests_dir );
            }
        } catch ( Exception $e ) {
            fwrite( STDERR, 'Extraction error: ' . $e->getMessage() . "\n" );
            return;
        }
    }
}

if ( ! file_exists( "$includes_dir/functions.php" ) ) {
    fwrite( STDERR, "WordPress test library not found.\n" );
    return;
}

if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
    define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
}

require $includes_dir . '/functions.php';

// Load the plugin.
tests_add_filter( 'muplugins_loaded', function () {
    require dirname( __DIR__ ) . '/sentient-forms.php';
} );

require $includes_dir . '/bootstrap.php';
