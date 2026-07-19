<?php
require dirname(__DIR__) . '/vendor/autoload.php';

// WordPress' test suite chmods upload directories when the process umask masks
// permissions. Windows bind mounts reject chmod(), so keep test-created dirs at
// the requested mode from the start.
umask( 0 );

$tests_dir    = __DIR__ . '/wordpress-tests-lib';
$includes_dir = $tests_dir . '/tests/phpunit/includes';
$tmp_dir      = __DIR__ . '/wp-temp';
$wp_dir       = __DIR__ . '/wordpress';
$project_root = dirname( __DIR__ );

$remove_broken_link = static function ( string $path ): void {
    if ( is_link( $path ) && ! file_exists( $path ) ) {
        unlink( $path );
    }
};

$remove_path = static function ( string $path ) use ( &$remove_path ): void {
    if ( is_link( $path ) || is_file( $path ) ) {
        unlink( $path );
        return;
    }

    if ( ! is_dir( $path ) ) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $items as $item ) {
        $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
    }

    rmdir( $path );
};

$ensure_directory = static function ( string $path ): bool {
    if ( is_dir( $path ) ) {
        return true;
    }

    if ( file_exists( $path ) ) {
        return false;
    }

    return mkdir( $path, 0775, true );
};

$remove_broken_link( $tests_dir );
$remove_broken_link( $tmp_dir );
$remove_broken_link( __DIR__ . '/wp.tar.gz' );

if ( ! file_exists( "$includes_dir/functions.php" ) ) {
    $archive      = __DIR__ . '/wp.tar.gz';
    $download_url = 'https://codeload.github.com/WordPress/wordpress-develop/tar.gz/refs/tags/6.5.4';

    if ( ! file_exists( $archive ) ) {
        $data = file_get_contents( $download_url );
        if ( false === $data ) {
            throw new RuntimeException( 'Failed to download WordPress test library.' );
        }
        file_put_contents( $archive, $data );
    }

    if ( file_exists( $archive ) ) {
        $remove_path( $tmp_dir );
        if ( ! $ensure_directory( $tmp_dir ) ) {
            throw new RuntimeException( "Failed to create {$tmp_dir}" );
        }

        $tar_command = sprintf(
            'tar --no-same-owner --no-same-permissions --touch -xzf %s -C %s 2>&1',
            escapeshellarg( $archive ),
            escapeshellarg( $tmp_dir )
        );
        exec( $tar_command, $tar_output, $tar_exit_code );

        if ( 0 !== $tar_exit_code && str_contains( implode( "\n", $tar_output ), 'Option --touch is not supported' ) ) {
            $tar_command = sprintf(
                'tar --no-same-owner --no-same-permissions -xzf %s -C %s 2>&1',
                escapeshellarg( $archive ),
                escapeshellarg( $tmp_dir )
            );
            $tar_output = [];
            exec( $tar_command, $tar_output, $tar_exit_code );
        }

        if ( 0 !== $tar_exit_code ) {
            throw new RuntimeException( 'Extraction error: ' . implode( "\n", $tar_output ) );
        }

        try {
            $extracted = glob( $tmp_dir . '/wordpress-develop-*' );
            if ( empty( $extracted ) || ! is_dir( $extracted[0] ) ) {
                throw new RuntimeException( 'Extracted WordPress test library was not found.' );
            }

            $remove_path( $tests_dir );
            if ( @rename( $extracted[0], $tests_dir ) ) {
                $remove_path( $tmp_dir );
            } else {
                // Windows bind mounts can reject directory renames from inside Docker.
                $tests_dir    = $extracted[0];
                $includes_dir = $tests_dir . '/tests/phpunit/includes';
            }
        } catch ( Exception $e ) {
            throw new RuntimeException( 'Extraction error: ' . $e->getMessage(), 0, $e );
        }
    }
}

if ( ! file_exists( "$includes_dir/functions.php" ) ) {
    throw new RuntimeException( 'WordPress test library not found.' );
}

if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
    define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
}

if ( ! defined( 'SENTIENT_FORMS_ALLOW_INSECURE_OUTBOUND_URLS' ) ) {
    define( 'SENTIENT_FORMS_ALLOW_INSECURE_OUTBOUND_URLS', true );
}

require $includes_dir . '/functions.php';
require_once __DIR__ . '/phpunit/helpers/async-fixtures.php';

$sentient_forms_test_plugin_file = getenv( 'SENTIENT_FORMS_TEST_PLUGIN_FILE' );
if ( ! is_string( $sentient_forms_test_plugin_file ) || '' === trim( $sentient_forms_test_plugin_file ) ) {
    $sentient_forms_test_plugin_file = dirname( __DIR__ ) . '/sentient-forms.php';
}

$sentient_forms_test_plugin_file = realpath( $sentient_forms_test_plugin_file );
if ( false === $sentient_forms_test_plugin_file || ! is_file( $sentient_forms_test_plugin_file ) ) {
    throw new RuntimeException( 'Sentient Forms test plugin file was not found.' );
}

// Load the plugin.
tests_add_filter( 'muplugins_loaded', function () use ( $sentient_forms_test_plugin_file ) {
    require $sentient_forms_test_plugin_file;
} );

/**
 * Seed async + license defaults so async tests are deterministic.
 */
tests_add_filter( 'plugins_loaded', function () use ( $project_root ) {
    $plugin = Sentient_Forms_Plugin::instance();

    // Ensure async tables exist before tests run.
    if ( class_exists( 'Sentient_Forms_Installer' ) ) {
        Sentient_Forms_Installer::maybe_upgrade();
    }

    // Seed license + CPS base URL for tests (mirrors dev harness defaults).
    $plugin->set_license_data( [
        'license_key'    => 'LIC-LOCAL-DEV',
        'license_status' => 'active',
        'license_id'     => 'local-license',
        'site_id'        => 'local-site',
        'proxy_api_key'  => 'proxy-local-123',
        'tier'           => 'dev',
        'expiry_date'    => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS * 30 ),
    ] );

    $settings = get_option( 'sentient_forms_settings', [] );
    $settings['cps_base_url'] = $settings['cps_base_url'] ?? 'http://cps-api:8080/v2';
    update_option( 'sentient_forms_settings', $settings );

    // Reset async stores between test runs.
    sentient_forms_tests_reset_async_state();
}, 20 );

require $includes_dir . '/bootstrap.php';
