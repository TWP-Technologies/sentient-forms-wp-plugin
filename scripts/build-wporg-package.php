<?php
/**
 * Build a clean Sentient Forms WordPress.org package directory.
 *
 * The package intentionally excludes tests, logs, docs, non-runtime lockfiles, and
 * top-level build tooling. Runtime dependencies and generated admin assets are
 * copied explicitly; generated asset source is documented through the public
 * release source URL in readme.txt and assets/dist/SOURCE.md.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$plugin_root    = dirname( __DIR__ );
$workspace_root = dirname( $plugin_root );

date_default_timezone_set( getenv( 'SENTIENT_FORMS_PACKAGE_TZ' ) ?: getenv( 'TZ' ) ?: 'America/Chicago' );

$timestamp      = date( 'Y/m/d/His' );
$default_base   = $workspace_root . '/temp/' . $timestamp . '-wporg-package';
$output_base    = $argv[1] ?? $default_base;
$package_dir    = rtrim( $output_base, DIRECTORY_SEPARATOR ) . '/sentient-forms';

$include_paths = [
    'assets',
    'includes',
    'languages',
    'vendor/woocommerce/action-scheduler',
    'CHANGELOG.md',
    'sentient-forms.php',
];

if ( file_exists( $plugin_root . '/readme.txt' ) )
{
    $include_paths[] = 'readme.txt';
}

if ( file_exists( $package_dir ) )
{
    remove_directory( $package_dir );
}

if ( ! mkdir( $package_dir, 0775, true ) && ! is_dir( $package_dir ) )
{
    fwrite( STDERR, "Unable to create package directory: {$package_dir}\n" );
    exit( 1 );
}

foreach ( $include_paths as $relative )
{
    $source = $plugin_root . '/' . $relative;
    if ( ! file_exists( $source ) )
    {
        continue;
    }

    $target = $package_dir . '/' . $relative;
    if ( is_dir( $source ) )
    {
        copy_directory( $source, $target );
        continue;
    }

    copy_file( $source, $target );
}

write_runtime_composer_manifest( $plugin_root . '/composer.json', $package_dir . '/composer.json' );

foreach ( [ 'languages' ] as $required_directory )
{
    $required_path = $package_dir . '/' . $required_directory;
    if ( ! is_dir( $required_path ) && ! mkdir( $required_path, 0775, true ) )
    {
        throw new RuntimeException( "Unable to create required package directory: {$required_path}" );
    }
}

if ( ! is_dir( $package_dir . '/assets/dist' ) )
{
    fwrite( STDERR, "Package is missing built admin assets. Run bun run build:wp first.\n" );
    exit( 1 );
}

echo "Built WordPress.org package directory:\n{$package_dir}\n";

/**
 * Copy a directory recursively while excluding non-runtime artifacts.
 */
function copy_directory( string $source, string $target ): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ( $iterator as $item )
    {
        $source_path = $item->getPathname();
        $relative    = ltrim( str_replace( $source, '', $source_path ), DIRECTORY_SEPARATOR );
        $relative    = str_replace( DIRECTORY_SEPARATOR, '/', $relative );

        if ( should_skip_package_path( $relative, $item->isDir() ) )
        {
            continue;
        }

        $target_path = $target . '/' . $relative;
        if ( $item->isDir() )
        {
            if ( ! is_dir( $target_path ) && ! mkdir( $target_path, 0775, true ) )
            {
                throw new RuntimeException( "Unable to create directory: {$target_path}" );
            }
            continue;
        }

        copy_file( $source_path, $target_path );
    }
}

/**
 * Copy a single file, creating the destination directory when necessary.
 */
function copy_file( string $source, string $target ): void
{
    $target_dir = dirname( $target );
    if ( ! is_dir( $target_dir ) && ! mkdir( $target_dir, 0775, true ) )
    {
        throw new RuntimeException( "Unable to create directory: {$target_dir}" );
    }

    if ( ! copy( $source, $target ) )
    {
        throw new RuntimeException( "Unable to copy {$source} to {$target}" );
    }
}

/**
 * Return whether a relative runtime path should be excluded from the package.
 */
function should_skip_package_path( string $relative, bool $is_dir ): bool
{
    $parts = explode( '/', $relative );

    foreach ( $parts as $part )
    {
        if ( str_starts_with( $part, '.' ) )
        {
            return true;
        }
    }

    if ( str_starts_with( $relative, 'admin/views/' ) )
    {
        return true;
    }

    if ( in_array( $relative, [ 'Dockerfile.dev', 'playwright.config.ts', 'vitest.config.ts' ], true ) )
    {
        return true;
    }

    if ( in_array( '.DS_Store', $parts, true ) || in_array( '__MACOSX', $parts, true ) )
    {
        return true;
    }

    if ( $is_dir && in_array( $relative, [ '.git', '.svelte-kit', 'build', 'docs', 'node_modules', 'playwright-report', 'test-results', 'tests' ], true ) )
    {
        return true;
    }

    return (bool) preg_match(
        '/(?:^|\/)(?:composer\.lock|bun\.lock|package-lock\.json|pnpm-lock\.yaml|yarn\.lock|.*\.map|\.env(?:\..*)?)$/',
        $relative
    );
}

/**
 * Write package-safe Composer metadata that does not advertise omitted tooling.
 */
function write_runtime_composer_manifest( string $source, string $target ): void
{
    if ( ! file_exists( $source ) )
    {
        return;
    }

    $decoded = json_decode( (string) file_get_contents( $source ), true );
    if ( ! is_array( $decoded ) )
    {
        throw new RuntimeException( "Unable to parse Composer manifest: {$source}" );
    }

    $runtime = [];
    foreach ( [ 'name', 'description', 'license', 'type', 'require' ] as $field )
    {
        if ( array_key_exists( $field, $decoded ) )
        {
            $runtime[ $field ] = $decoded[ $field ];
        }
    }

    $encoded = json_encode( $runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    if ( ! is_string( $encoded ) )
    {
        throw new RuntimeException( 'Unable to encode runtime Composer manifest.' );
    }

    copy_file_contents( $encoded . PHP_EOL, $target );
}

/**
 * Write file contents, creating the destination directory when necessary.
 */
function copy_file_contents( string $contents, string $target ): void
{
    $target_dir = dirname( $target );
    if ( ! is_dir( $target_dir ) && ! mkdir( $target_dir, 0775, true ) )
    {
        throw new RuntimeException( "Unable to create directory: {$target_dir}" );
    }

    if ( false === file_put_contents( $target, $contents ) )
    {
        throw new RuntimeException( "Unable to write {$target}" );
    }
}

/**
 * Remove a package directory recursively.
 */
function remove_directory( string $path ): void
{
    if ( basename( $path ) !== 'sentient-forms' )
    {
        throw new RuntimeException( "Refusing to remove unexpected path: {$path}" );
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $iterator as $item )
    {
        if ( $item->isDir() )
        {
            rmdir( $item->getPathname() );
            continue;
        }

        unlink( $item->getPathname() );
    }

    rmdir( $path );
}
