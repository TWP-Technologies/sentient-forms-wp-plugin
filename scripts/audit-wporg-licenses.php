<?php
/**
 * Audit Sentient Forms WordPress.org package license metadata.
 *
 * This is a deterministic preflight for the bundled runtime package. It does
 * not replace WordPress.org review, but it keeps known license regressions from
 * reaching the review artifact.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$package_mode = false;
$root_arg     = null;

foreach ( array_slice( $argv, 1 ) as $arg )
{
    if ( '--package' === $arg )
    {
        $package_mode = true;
        continue;
    }

    if ( null === $root_arg )
    {
        $root_arg = $arg;
        continue;
    }

    fwrite( STDERR, "Usage: php scripts/audit-wporg-licenses.php [--package] [plugin-directory]\n" );
    exit( 1 );
}

$root = realpath( $root_arg ?? dirname( __DIR__ ) );

if ( false === $root || ! is_dir( $root ) )
{
    fwrite( STDERR, "Usage: php scripts/audit-wporg-licenses.php [--package] [plugin-directory]\n" );
    exit( 1 );
}

$issues = [];

$issues = array_merge( $issues, audit_plugin_header_license( $root ) );
$issues = array_merge( $issues, audit_readme_license( $root ) );
$issues = array_merge( $issues, audit_composer_license( $root ) );
$issues = array_merge( $issues, audit_admin_app_package_license( $root ) );
$issues = array_merge( $issues, audit_bundled_dependency_licenses( $root ) );

if ( $package_mode )
{
    $issues = array_merge( $issues, audit_package_runtime_shape( $root ) );
}

if ( [] !== $issues )
{
    echo "Sentient Forms WordPress.org license audit failed:\n";
    foreach ( $issues as $issue )
    {
        echo "- {$issue}\n";
    }
    exit( 1 );
}

$mode = $package_mode ? 'package' : 'source tree';
echo "Sentient Forms WordPress.org {$mode} license audit passed for {$root}.\n";

/**
 * Validate the main plugin file license headers.
 *
 * @return array<int,string>
 */
function audit_plugin_header_license( string $root ): array
{
    $plugin_file = $root . '/sentient-forms.php';
    if ( ! file_exists( $plugin_file ) )
    {
        return [ 'Missing sentient-forms.php plugin entry point.' ];
    }

    $contents = file_get_contents( $plugin_file );
    if ( false === $contents )
    {
        return [ 'Could not read sentient-forms.php.' ];
    }

    $issues      = [];
    $license      = parse_header_value( $contents, 'License' );
    $license_uri  = parse_header_value( $contents, 'License URI' );
    $spdx_license = parse_header_value( $contents, 'SPDX-License-Identifier' );

    if ( null === $license || ! is_gpl_2_or_later_license( $license ) )
    {
        $issues[] = 'sentient-forms.php License header must remain GPLv2 or later.';
    }

    if ( null === $license_uri || ! str_contains( strtolower( $license_uri ), 'gnu.org/licenses/gpl-2.0' ) )
    {
        $issues[] = 'sentient-forms.php License URI must point to the GPL-2.0 license.';
    }

    if ( 'GPL-2.0-or-later' !== $spdx_license )
    {
        $issues[] = 'sentient-forms.php SPDX-License-Identifier must remain GPL-2.0-or-later.';
    }

    return $issues;
}

/**
 * Validate the WordPress.org readme license headers.
 *
 * @return array<int,string>
 */
function audit_readme_license( string $root ): array
{
    $readme = $root . '/readme.txt';
    if ( ! file_exists( $readme ) )
    {
        return [ 'Missing readme.txt.' ];
    }

    $contents = file_get_contents( $readme );
    if ( false === $contents )
    {
        return [ 'Could not read readme.txt.' ];
    }

    $issues      = [];
    $license     = parse_readme_header_value( $contents, 'License' );
    $license_uri = parse_readme_header_value( $contents, 'License URI' );

    if ( null === $license || ! is_gpl_2_or_later_license( $license ) )
    {
        $issues[] = 'readme.txt License header must remain GPLv2 or later.';
    }

    if ( null === $license_uri || ! str_contains( strtolower( $license_uri ), 'gnu.org/licenses/gpl-2.0' ) )
    {
        $issues[] = 'readme.txt License URI must point to the GPL-2.0 license.';
    }

    return $issues;
}

/**
 * Validate package composer metadata when present.
 *
 * @return array<int,string>
 */
function audit_composer_license( string $root ): array
{
    $composer = $root . '/composer.json';
    if ( ! file_exists( $composer ) )
    {
        return [];
    }

    $decoded = json_decode( (string) file_get_contents( $composer ), true );
    if ( ! is_array( $decoded ) )
    {
        return [ 'composer.json is not valid JSON.' ];
    }

    $license = $decoded['license'] ?? null;
    if ( 'GPL-2.0-or-later' !== $license )
    {
        return [ 'composer.json license must remain GPL-2.0-or-later.' ];
    }

    return [];
}

/**
 * Validate admin app package metadata when source is present.
 *
 * @return array<int,string>
 */
function audit_admin_app_package_license( string $root ): array
{
    $admin_app = $root . '/admin-app';
    if ( ! is_dir( $admin_app ) )
    {
        return [];
    }

    $package = $admin_app . '/package.json';
    if ( ! file_exists( $package ) )
    {
        return [ 'admin-app/package.json is missing from the source tree.' ];
    }

    $decoded = json_decode( (string) file_get_contents( $package ), true );
    if ( ! is_array( $decoded ) )
    {
        return [ 'admin-app/package.json is not valid JSON.' ];
    }

    $license = $decoded['license'] ?? null;
    if ( 'GPL-2.0-or-later' !== $license )
    {
        return [ 'admin-app/package.json license must remain GPL-2.0-or-later.' ];
    }

    return [];
}

/**
 * Validate bundled runtime dependency licenses that ship in the package.
 *
 * @return array<int,string>
 */
function audit_bundled_dependency_licenses( string $root ): array
{
    $action_scheduler = $root . '/vendor/woocommerce/action-scheduler';
    if ( ! is_dir( $action_scheduler ) )
    {
        return [];
    }

    $issues = [];
    $readme = read_file_or_issue( $action_scheduler . '/readme.txt', 'Action Scheduler readme.txt' );
    if ( is_array( $readme ) )
    {
        $issues = array_merge( $issues, $readme );
    }
    elseif ( 'GPLv3' !== parse_readme_header_value( $readme, 'License' ) )
    {
        $issues[] = 'Action Scheduler bundled readme.txt must declare License: GPLv3.';
    }

    $license = read_file_or_issue( $action_scheduler . '/license.txt', 'Action Scheduler license.txt' );
    if ( is_array( $license ) )
    {
        $issues = array_merge( $issues, $license );
    }
    elseif ( ! contains_all( strtolower( $license ), [ 'gnu general public license', 'version 3' ] ) )
    {
        $issues[] = 'Action Scheduler license.txt must contain GPL version 3 license text.';
    }

    $cron_license = read_file_or_issue( $action_scheduler . '/lib/cron-expression/LICENSE', 'Action Scheduler cron-expression LICENSE' );
    if ( is_array( $cron_license ) )
    {
        $issues = array_merge( $issues, $cron_license );
    }
    elseif ( ! contains_all( $cron_license, [ 'Permission is hereby granted', 'without restriction' ] ) )
    {
        $issues[] = 'Bundled cron-expression license must contain MIT license text.';
    }

    return $issues;
}

/**
 * Validate package-only runtime shape constraints tied to license review.
 *
 * @return array<int,string>
 */
function audit_package_runtime_shape( string $root ): array
{
    $issues = [];

    if ( is_dir( $root . '/vendor/bin' ) )
    {
        $issues[] = 'Package must not include vendor/bin development executables.';
    }

    $allowed_vendor_paths = [
        'vendor/woocommerce',
        'vendor/woocommerce/action-scheduler',
    ];

    $vendor = $root . '/vendor';
    if ( ! is_dir( $vendor ) )
    {
        return $issues;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $vendor, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ( $iterator as $item )
    {
        if ( ! $item->isDir() )
        {
            continue;
        }

        $relative = ltrim( str_replace( $root, '', $item->getPathname() ), DIRECTORY_SEPARATOR );
        $relative = str_replace( DIRECTORY_SEPARATOR, '/', $relative );

        if ( is_allowed_vendor_runtime_path( $relative, $allowed_vendor_paths ) )
        {
            continue;
        }

        $issues[] = "Package includes unexpected bundled vendor directory: {$relative}";
    }

    return $issues;
}

/**
 * Return whether a bundled vendor path is part of the expected runtime set.
 *
 * @param array<int,string> $allowed_vendor_paths Allowed vendor path prefixes.
 */
function is_allowed_vendor_runtime_path( string $relative, array $allowed_vendor_paths ): bool
{
    foreach ( $allowed_vendor_paths as $allowed )
    {
        if ( $relative === $allowed || str_starts_with( $relative, $allowed . '/' ) )
        {
            return true;
        }
    }

    return false;
}

/**
 * Read a file or return one audit issue.
 *
 * @return string|array<int,string>
 */
function read_file_or_issue( string $path, string $label ): string|array
{
    if ( ! file_exists( $path ) )
    {
        return [ "Missing {$label}." ];
    }

    $contents = file_get_contents( $path );
    if ( false === $contents )
    {
        return [ "Could not read {$label}." ];
    }

    return $contents;
}

/**
 * Parse a plugin header value from a PHP file docblock.
 */
function parse_header_value( string $contents, string $field ): ?string
{
    if ( ! preg_match( '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(.+)$/mi', $contents, $matches ) )
    {
        return null;
    }

    return trim( $matches[1] );
}

/**
 * Parse a WordPress.org readme header value.
 */
function parse_readme_header_value( string $contents, string $field ): ?string
{
    if ( ! preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(.+)$/mi', $contents, $matches ) )
    {
        return null;
    }

    return trim( $matches[1] );
}

/**
 * Return whether a license string clearly means GPL v2 or later.
 */
function is_gpl_2_or_later_license( string $license ): bool
{
    $normalized = preg_replace( '/[^a-z0-9]+/', '', strtolower( $license ) ) ?? '';

    return in_array( $normalized, [ 'gplv2orlater', 'gpl20orlater', 'gpl2orlater' ], true );
}

/**
 * Return whether the haystack contains all required fragments.
 *
 * @param array<int,string> $needles Required fragments.
 */
function contains_all( string $haystack, array $needles ): bool
{
    foreach ( $needles as $needle )
    {
        if ( ! str_contains( $haystack, $needle ) )
        {
            return false;
        }
    }

    return true;
}
